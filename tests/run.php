<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/database.php';
require_once __DIR__ . '/support.php';

use function Exhibit\Database\configuration;

function rejects(array $environment): void
{
    try {
        configuration($environment);
    } catch (InvalidArgumentException $error) {
        check(!str_contains($error->getMessage(), 'secret-sentinel'), 'Configuration error disclosed credentials.');
        return;
    }
    throw new RuntimeException('Invalid configuration was accepted.');
}

$live = in_array('--live', $argv, true);
if (array_diff(array_slice($argv, 1), ['--live']) !== []) {
    fwrite(STDERR, "Usage: php tests/run.php [--live]\n");
    exit(2);
}

$base = [
    'DATABASE_URL' => 'postgresql://exhibit:secret-sentinel@localhost/exhibit_test',
    'DB_SSLMODE' => 'disable',
];
$tests = [];
$tests['URL parsing and safe PDO defaults (no connection)'] = static function () use ($base): void {
    $config = configuration($base);
    check($config['dsn'] === 'pgsql:host=localhost;port=5432;dbname=exhibit_test;sslmode=disable;connect_timeout=5', 'Unexpected DSN.');
    check(!str_contains($config['dsn'], 'secret-sentinel'), 'Password must not be in the DSN.');
    check($config['username'] === 'exhibit' && $config['password'] === 'secret-sentinel', 'Credentials not parsed.');
    check($config['options'] === [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ], 'Unsafe PDO options.');
};
$tests['Encoded credentials, alternate scheme, port, and IPv6'] = static function () use ($base): void {
    $config = configuration(array_replace($base, [
        'DATABASE_URL' => 'postgres://user%40example.test:p%40ss%3Bword%2B%25@[::1]:5433/exhibit_test',
    ]));
    check($config['username'] === 'user@example.test', 'Username decoding failed.');
    check($config['password'] === 'p@ss;word+%', 'Password decoding failed.');
    check(str_contains($config['dsn'], 'host=::1;port=5433;'), 'IPv6 or port parsing failed.');
};
$tests['TLS defaults to verified hostname and readable CA'] = static function () use ($base): void {
    $config = configuration([
        'DATABASE_URL' => 'postgresql://exhibit:secret-sentinel@db.example.test/exhibit',
        'DB_SSLROOTCERT' => __FILE__,
    ]);
    check(str_contains($config['dsn'], 'sslmode=verify-full;'), 'TLS must default to verify-full.');
    check(str_contains($config['dsn'], 'sslrootcert='), 'CA path missing.');
    rejects(array_replace($base, ['DB_SSLMODE' => 'verify-full']));
    rejects(array_replace($base, ['DB_SSLMODE' => 'verify-full', 'DB_SSLROOTCERT' => __DIR__]));
    rejects(array_replace($base, ['DB_SSLMODE' => 'verify-full', 'DB_SSLROOTCERT' => __FILE__ . ';sslmode=disable']));
};

$invalidUrls = [
    '', 'mysql://user:secret-sentinel@localhost/db',
    'postgresql://localhost/db', 'postgresql://user@localhost/db',
    'postgresql://user:secret-sentinel@localhost', 'postgresql://user:secret-sentinel@localhost/',
    'postgresql://user:secret-sentinel@localhost:0/db',
    'postgresql://user:secret-sentinel@localhost:65536/db',
    'postgresql://user:secret-sentinel@localhost/db?sslmode=disable',
    'postgresql://user:secret-sentinel@localhost/db#fragment',
    'postgresql://user:secret-sentinel@localhost/db%3Bsslmode%3Ddisable',
    'postgresql://user:secret-sentinel@localhost/db%00',
    'postgresql://user:secret-sentinel@localhost/db%ZZ',
    'postgresql://user:secret-sentinel@localhost/db/extra',
    'postgresql://user:secret-sentinel@localhost%3Bhost=elsewhere/db',
    'postgresql://user%00:secret-sentinel@localhost/db',
    'postgresql://user:secret-sentinel%0A@localhost/db',
    "postgresql://user:secret-sentinel\n@localhost/db",
];
foreach ($invalidUrls as $index => $url) {
    $tests['Reject invalid URL #' . ($index + 1)] = static fn () => rejects(array_replace($base, ['DATABASE_URL' => $url]));
}
$tests['Reject missing environment and insecure remote TLS'] = static function () use ($base): void {
    rejects([]);
    rejects(array_replace($base, ['DATABASE_URL' => 'postgresql://user:secret-sentinel@db.example.test/db']));
    foreach (['require', 'prefer', 'verify-ca', 'invalid'] as $mode) {
        rejects(array_replace($base, ['DB_SSLMODE' => $mode]));
    }
};
$tests['Development profile rejects production, test and alternate local database targets before connecting'] = static function (): void {
    $local = ['APP_ENV' => 'development', 'DB_SSLMODE' => 'disable',
        'DATABASE_URL' => 'postgresql://exhibit_dev_app:local-test-sentinel@127.0.0.1:55432/exhibit_development'];
    check(str_contains(configuration($local)['dsn'], 'host=127.0.0.1;port=55432;dbname=exhibit_development;'),
        'Dedicated development connection rejected.');
    foreach ([
        'postgresql://exhibit_dev_app:local-test-sentinel@db.example.test:55432/exhibit_development',
        'postgresql://exhibit_dev_app:local-test-sentinel@localhost:55432/exhibit_development',
        'postgresql://exhibit_dev_app:local-test-sentinel@127.0.0.1:5432/exhibit_development',
        'postgresql://exhibit_dev_app:local-test-sentinel@127.0.0.1:55432/exhibit_test_example',
        'postgresql://exhibit_dev_app:local-test-sentinel@127.0.0.1:55432/production',
    ] as $url) {
        rejects(array_replace($local, ['DATABASE_URL' => $url]));
    }
};
$tests['Test profile cannot target persistent development or production databases'] = static function (): void {
    $test = ['APP_ENV' => 'test', 'DB_SSLMODE' => 'disable',
        'DATABASE_URL' => 'postgresql://test:local-test-sentinel@127.0.0.1:55432/exhibit_test_profile'];
    check(str_contains(configuration($test)['dsn'], 'dbname=exhibit_test_profile'), 'Isolated test target rejected.');
    foreach (['exhibit_development', 'production', 'exhibit_test', 'exhibit_test_'] as $name) {
        rejects(array_replace($test, ['DATABASE_URL' => 'postgresql://test:local-test-sentinel@127.0.0.1:55432/' . $name]));
    }
    rejects(array_replace($test, ['DATABASE_URL' => 'postgresql://test:local-test-sentinel@db.example.test/exhibit_test_profile']));
    rejects(array_replace($test, ['APP_ENV' => 'developmnt']));
};
$tests['PDO and CLI helpers enforce the process profile before connecting or migrating'] = static function (): void {
    $saved = [];
    foreach (['APP_ENV', 'DATABASE_URL', 'DB_SSLMODE'] as $name) $saved[$name] = getenv($name);
    try {
        putenv('APP_ENV=development');
        putenv('DATABASE_URL=postgresql://test:local-test-sentinel@db.example.test:55432/exhibit_development');
        putenv('DB_SSLMODE=disable');
        try {
            \Exhibit\Database\connect();
            throw new RuntimeException('Development process opened a remote database.');
        } catch (InvalidArgumentException $error) {
            check(str_contains($error->getMessage(), 'Development connections require'), 'Profile was not checked before connecting.');
        }
        foreach ([
            ['auth-migrate.php', '--check', '--database=exhibit_development'],
            ['diagnose.php', '--database=exhibit_development'],
            ['diagnose-native.php', '--database=exhibit_development', '--psql=missing-psql.exe'],
        ] as $arguments) {
            $script = array_shift($arguments);
            $process = proc_open(
                array_merge([PHP_BINARY, '-n', __DIR__ . '/../database/' . $script], $arguments),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            check(is_resource($process), 'Could not start profile isolation CLI test.');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            check(proc_close($process) === 1 && str_contains($errors, 'Development connections require'),
                'CLI omitted the development profile: ' . $script);
            check(!str_contains($output . $errors, 'secret-sentinel'), 'CLI disclosed its connection credential.');
        }
    } finally {
        foreach ($saved as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
    }
};
$tests['Exactly four tables with reviewed columns and types'] = static function (): void {
    $sql = file_get_contents(__DIR__ . '/../database/migrations/001_initial.sql');
    check($sql !== false, 'Migration unavailable.');
    preg_match_all('/CREATE TABLE public\.(\w+) \((.*?)\n\);/s', $sql, $tables, PREG_SET_ORDER);
    check(array_column($tables, 1) === array_keys(expectedColumns()), 'Expected only the four Exhibit tables.');
    foreach ($tables as $table) {
        preg_match_all('/^    (\w+) (bigint|text|integer|jsonb|timestamptz)\b/m', $table[2], $matches, PREG_SET_ORDER);
        $columns = [];
        foreach ($matches as $match) {
            $columns[$match[1]] = $match[2] === 'timestamptz' ? 'timestamp with time zone' : $match[2];
        }
        check($columns === expectedColumns()[$table[1]], 'Column mismatch: ' . $table[1]);
        check(str_contains($table[2], 'id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY'), 'Identity key missing.');
        foreach (['created_at', 'updated_at'] as $column) {
            check(str_contains($table[2], "$column timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP"), 'Timestamp default missing.');
        }
    }
};
$tests['Migration constraints, ordering, deletion rules, and timestamps'] = static function (): void {
    $sql = file_get_contents(__DIR__ . '/../database/migrations/001_initial.sql');
    check($sql !== false, 'Migration unavailable.');
    check(preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK)\s*;/m', $sql) === 0, 'Migration transaction boundaries must belong to the manual runner.');
    foreach ([
        "email text NOT NULL CHECK (btrim(email) <> '' AND email = btrim(email))",
        'CREATE UNIQUE INDEX users_email_lower_unique ON public.users (lower(email))',
        "password_hash text NOT NULL CHECK (btrim(password_hash) <> '')",
        "display_name text NOT NULL CHECK (btrim(display_name) <> '')",
        'owner_id bigint NOT NULL REFERENCES public.users (id) ON DELETE RESTRICT',
        'CREATE INDEX exhibitions_owner_id_index ON public.exhibitions (owner_id)',
        "slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')",
        "cover jsonb CHECK (jsonb_typeof(cover) = 'object')",
        "status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published'))",
        "(status = 'draft' AND published_at IS NULL)",
        "(status = 'published' AND published_at IS NOT NULL)",
        'exhibition_id bigint NOT NULL REFERENCES public.exhibitions (id) ON DELETE CASCADE',
        'chapter_id bigint NOT NULL REFERENCES public.chapters (id) ON DELETE CASCADE',
        'UNIQUE (exhibition_id, position) DEFERRABLE INITIALLY IMMEDIATE',
        'UNIQUE (chapter_id, position) DEFERRABLE INITIALLY IMMEDIATE',
        "type text NOT NULL CHECK (type IN ('text', 'figure', 'artifact'))",
        "content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object')",
        'NEW.updated_at = statement_timestamp();',
    ] as $fragment) {
        check(str_contains($sql, $fragment), 'Missing migration contract: ' . $fragment);
    }
    check(substr_count($sql, 'position integer NOT NULL CHECK (position > 0)') === 2, 'Positive ordering missing.');
    check(substr_count($sql, "title text NOT NULL CHECK (btrim(title) <> '')") === 2, 'Required titles missing.');
    foreach (array_keys(expectedColumns()) as $table) {
        check(preg_match('/BEFORE UPDATE ON public\.' . $table
            . '\R\s+FOR EACH ROW EXECUTE FUNCTION public\.exhibit_set_updated_at\(\)/', $sql) === 1,
            'Timestamp trigger missing: ' . $table);
    }
};

$tests['CA paths with spaces and apostrophes are libpq-escaped'] = static function () use ($base): void {
    $temporary = tempnam(sys_get_temp_dir(), 'exhibit-ca-');
    check($temporary !== false, 'Could not create CA path fixture.');
    $path = $temporary . " space's.pem";
    try {
        check(rename($temporary, $path), 'Could not rename CA path fixture.');
        $config = configuration(array_replace($base, ['DB_SSLMODE' => 'verify-full', 'DB_SSLROOTCERT' => $path]));
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $path);
        check(str_ends_with($config['dsn'], ";sslrootcert='$escaped'"), 'CA filename was not escaped for libpq.');
    } finally {
        if (is_file($path)) {
            unlink($path);
        } elseif (is_file($temporary)) {
            unlink($temporary);
        }
    }
};
$tests['PostgreSQL behavior tests cannot target remote or non-test databases'] = static function (): void {
    foreach ([
        false,
        'postgresql://user:password@db.example.test/exhibit_test_disposable',
        'postgresql://user:password@127.0.0.1/exhibit',
        'postgresql://user:password@localhost/postgres',
        'postgresql://user:password@localhost/exhibit_test_',
        'postgresql://user:password@localhost/exhibit_test_disposable/extra',
        'postgresql://user:password@localhost:99999/exhibit_test_disposable',
    ] as $url) {
        try {
            testEnvironment(['TEST_DATABASE_URL' => $url]);
        } catch (InvalidArgumentException) {
            continue;
        }
        throw new RuntimeException('Unsafe PostgreSQL test target accepted.');
    }
    $environment = testEnvironment(['TEST_DATABASE_URL' => 'postgresql://user:password@127.0.0.1/exhibit_test_disposable']);
    check($environment['DB_SSLMODE'] === false, 'Test TLS must not silently default to disabled.');
};
$tests['Manual migration defaults to a nonconnecting plan and rejects incomplete apply flags'] = static function (): void {
    foreach ([
        [[], 0],
        [['--plan'], 0],
        [['--apply'], 2],
        [['--check'], 2],
        [['--apply', '--database='], 2],
        [['--database=exhibit'], 2],
        [['--apply', '--database=exhibit', '--unexpected'], 2],
    ] as [$arguments, $expectedExit]) {
        $process = proc_open(
            array_merge([PHP_BINARY, '-n', __DIR__ . '/../database/migrate.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        check(is_resource($process), 'Could not start migration CLI test.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === $expectedExit, 'Unexpected migration CLI exit status.');
        if ($expectedExit === 0) {
            check(str_contains($output, 'No connection opened.'), 'Plan attempted a connection.');
            check(preg_match('/SHA-256: [a-f0-9]{64}/', $output) === 1, 'Migration checksum missing.');
        } else {
            check(str_contains($errors, 'Usage:'), 'Incomplete apply must fail with usage.');
        }
    }
};
$tests['Private directories, SQL, secrets, and configuration are denied by Apache'] = static function (): void {
    $root = dirname(__DIR__);
    foreach (['backend', 'database', 'tests', 'docs', 'docker'] as $directory) {
        check(trim(file_get_contents($root . '/' . $directory . '/.htaccess')) === 'Require all denied', 'Missing private directory deny: ' . $directory);
    }
    foreach (['/.htaccess', '/docker/apache-private.conf'] as $file) {
        $config = file_get_contents($root . $file);
        check(preg_match('/<FilesMatch "([^"]+)">/', $config, $match) === 1, 'Missing private file matcher.');
        foreach (['.env', '.env.production', '.git', 'README.md', 'Dockerfile', 'initial.sql', 'php.ini', 'private.pem', 'private.key', 'backup.dump', 'initial.sql.gz'] as $name) {
            check(preg_match('~' . $match[1] . '~', $name) === 1, 'Private filename is not denied: ' . $name);
        }
        foreach (['index.php', 'studio.php', 'style.css', 'studio.js', 'favicon.svg'] as $name) {
            check(preg_match('~' . $match[1] . '~', $name) === 0, 'Public filename is denied: ' . $name);
        }
        check(str_contains($config, 'Options -Indexes'), 'Directory listing must be disabled.');
        check(str_contains($config, 'RedirectMatch 403 "(?i)(?:^|/)\."'), 'Hidden directories must be denied, not just hidden filenames.');
    }
};
$tests['No automatic migrations; public database access restricted to published exhibition routes'] = static function (): void {
    $root = dirname(__DIR__);
    foreach (glob($root . '/*.php') as $page) {
        $source = file_get_contents($page);
        check(!preg_match('/(?:database[\/\\\\]|new\s+PDO\b|installInitialSchema\s*\()/i', $source), 'Screen must not migrate or connect directly.');
        if (!in_array(basename($page), ['register.php', 'sign-in.php', 'forgot-password.php', 'reset-password.php',
            'verify-email.php', 'logout.php', 'my-exhibitions.php', 'new-exhibition.php', 'studio.php', 'publish.php',
            'exhibition.php', 'view.php'], true)) {
            check(!str_contains($source, '/backend/'), 'Public content must not require authentication or database access.');
        }
    }
    foreach (['exhibition.php', 'view.php'] as $page) {
        check(str_contains(file_get_contents($root . '/' . $page), "/backend/public-exhibition.php"), 'Public exhibition must use the published-only controller.');
    }
    $docker = file_get_contents($root . '/Dockerfile');
    check(str_contains($docker, 'docker-php-ext-install pdo_pgsql'), 'Docker PostgreSQL extension missing.');
    check(str_contains($docker, 'CMD ["apache2-foreground"]'), 'Unexpected startup command.');
    check(!str_contains($docker, 'migrate.php'), 'Docker must not run migrations.');
    $ini = parse_ini_file($root . '/docker/php-production.ini', false, INI_SCANNER_TYPED);
    check($ini['display_errors'] === false && $ini['display_startup_errors'] === false
        && $ini['log_errors'] === true && $ini['zend.exception_ignore_args'] === true, 'Production PHP diagnostics are not protected.');
};
$tests['Read-only preflight enforces identity, permissions, primary state, and empty user schemas'] = static function (): void {
    require_once __DIR__ . '/preflight.php';
    validatePreflightGuards();
};
$tests['Connection diagnostics classify errors without returning raw details'] = static function (): void {
    foreach ([
        ['could not translate host name "secret-sentinel" to address', 'DNS'],
        ['SSL error: certificate verify failed secret-sentinel', 'TLS'],
        ['server does not support SSL secret-sentinel', 'TLS'],
        ['password authentication failed for user secret-sentinel', 'AUTHENTICATION'],
        ['no pg_hba.conf entry for host secret-sentinel', 'ACCESS_POLICY'],
        ['database "secret-sentinel" does not exist', 'DATABASE'],
        ['connection timed out secret-sentinel', 'NETWORK'],
        ['connection refused secret-sentinel', 'NETWORK'],
        ['unexpected driver message secret-sentinel', 'UNCLASSIFIED'],
    ] as [$message, $expected]) {
        $category = \Exhibit\Database\connectionFailureCategory(new PDOException($message));
        check($category === $expected && !str_contains($category, 'secret-sentinel'), 'Unsafe or incorrect diagnostic category.');
    }
};
$diagnosticCases = [
    ['TLS detail in errorInfo', '08006', 'SSL error: certificate verify failed', 'TLS', 'CERTIFICATE_VERIFICATION'],
    ['TLS hostname mismatch', '08006', 'server certificate does not match host name', 'TLS', 'HOSTNAME_VERIFICATION'],
    ['TLS root bundle load', '08006', 'root certificate file could not be read', 'TLS', 'CA_FILE'],
    ['TLS protocol handshake', '08006', 'SSL error: unsupported protocol', 'TLS', 'TLS_HANDSHAKE'],
    ['PostgreSQL password rejection', '28P01', 'password authentication failed', 'AUTHENTICATION', 'CREDENTIAL_REJECTION'],
    ['Structured password rejection', '28P01', '', 'AUTHENTICATION', 'INVALID_PASSWORD'],
    ['PostgreSQL access policy', '28000', 'no pg_hba.conf entry', 'ACCESS_POLICY', 'SERVER_ACCESS_RULE'],
    ['Client certificate policy', '28000', 'connection requires a valid client certificate', 'ACCESS_POLICY', 'SERVER_ACCESS_RULE'],
    ['Ambiguous authorization', '28000', '', 'AUTHENTICATION_OR_POLICY', 'AUTHORIZATION_REJECTED'],
    ['Missing database', '3D000', '', 'DATABASE', 'DATABASE_NOT_FOUND'],
    ['Insufficient privileges', '42501', '', 'ACCESS_POLICY', 'INSUFFICIENT_PRIVILEGE'],
    ['Connection limit', '53300', '', 'SERVER_AVAILABILITY', 'CONNECTION_LIMIT'],
    ['Starting server', '57P03', '', 'SERVER_AVAILABILITY', 'SERVER_NOT_READY'],
    ['Generic connection SQLSTATE', '08006', '', 'CONNECTION', 'CONNECTION_SQLSTATE_ONLY'],
    ['Server rejected connection', '08004', '', 'CONNECTION', 'SERVER_REJECTED_CONNECTION'],
    ['Invalid libpq option', '08006', 'invalid connection option', 'CLIENT_CONFIGURATION', 'CONNECTION_OPTION'],
    ['Old libpq SCRAM support', '08006', 'SCRAM authentication requires libpq version 10 or above', 'CLIENT_COMPATIBILITY', 'AUTH_PROTOCOL_SUPPORT'],
    ['Windows transport reset', '08006', 'An existing connection was forcibly closed by the remote host', 'NETWORK', 'TRANSPORT_FAILURE'],
    ['Driver DNS failure', '08006', 'could not translate host name', 'DNS', 'HOST_RESOLUTION'],
    ['Unknown SQLSTATE redaction', 'SECRET', '', 'UNCLASSIFIED', 'NO_RECOGNIZED_SIGNAL'],
];
foreach ($diagnosticCases as [$name, $state, $driverMessage, $category, $reason]) {
    $tests['Safe PDO classification: ' . $name] = static function () use ($state, $driverMessage, $category, $reason): void {
        $secret = 'private-sentinel-url-host-ip-password';
        $error = new PDOException('Generic PDO wrapper ' . $secret);
        $error->errorInfo = [$state, 7, $driverMessage . ($driverMessage !== '' ? ' ' . $secret : '')];
        $details = \Exhibit\Database\connectionFailureDetails($error);
        check($details['category'] === $category && $details['reason'] === $reason, 'Structured PDO classification mismatch.');
        check($details['sqlstate'] === ($state === 'SECRET' ? 'UNAVAILABLE' : $state), 'SQLSTATE was not safely allowlisted.');
        check($details['driver_detail'] === ($driverMessage === '' ? 'absent' : 'present'), 'Driver detail availability mismatch.');
        $summary = \Exhibit\Database\connectionFailureSummary($error);
        check(!str_contains($summary, $secret) && !str_contains($summary, 'SECRET')
            && !str_contains($summary, 'Generic PDO wrapper'), 'Safe summary disclosed raw exception fields.');
    };
}
$tests['PDO message SQLSTATE fallback and missing/malformed errorInfo are safe'] = static function (): void {
    $error = new PDOException('SQLSTATE[28P01] private-sentinel');
    $error->errorInfo = null;
    check(\Exhibit\Database\connectionFailureDetails($error)['reason'] === 'INVALID_PASSWORD', 'Message SQLSTATE fallback failed.');
    $error = new PDOException('private-sentinel');
    $error->errorInfo = ['private-sentinel', new stdClass(), new stdClass()];
    $details = \Exhibit\Database\connectionFailureDetails($error);
    check($details === [
        'category' => 'UNCLASSIFIED', 'reason' => 'NO_RECOGNIZED_SIGNAL',
        'sqlstate' => 'UNAVAILABLE', 'driver_detail' => 'absent',
    ], 'Malformed diagnostic metadata was not handled safely.');
};
$tests['Native comparison preserves the exact target, verified TLS, decoded credentials, and CA filename'] = static function (): void {
    require_once __DIR__ . '/../database/diagnose-native.php';
    $environment = [
        'DATABASE_URL' => 'postgresql://user%40example.test:p%40ss%3Bword%2B%25@db.example.test:5433/exhibit',
        'DB_SSLMODE' => 'verify-full',
        'DB_SSLROOTCERT' => __FILE__,
    ];
    $native = nativeEnvironment($environment, 'exhibit', [
        'PATH' => 'kept', 'DATABASE_URL' => 'must-not-inherit',
        'PGSERVICE' => 'must-not-inherit', 'PGHOSTADDR' => 'must-not-inherit',
        'PGSSLMODE' => 'disable', 'PGOPTIONS' => 'must-not-inherit',
    ]);
    check($native['PGHOST'] === 'db.example.test' && $native['PGPORT'] === '5433'
        && $native['PGDATABASE'] === 'exhibit', 'Native target changed.');
    check($native['PGUSER'] === 'user@example.test' && $native['PGPASSWORD'] === 'p@ss;word+%', 'Native credential decoding differs from PDO.');
    check($native['PGSSLMODE'] === 'verify-full' && $native['PGSSLROOTCERT'] === __FILE__, 'Native TLS policy/path changed.');
    check($native['PATH'] === 'kept' && !isset($native['DATABASE_URL'], $native['PGSERVICE'], $native['PGHOSTADDR']), 'Native environment inherited overriding connection settings.');
    check(str_contains($native['PGOPTIONS'], 'default_transaction_read_only=on'), 'Native session is not read-only by default.');
    foreach ([
        [$environment, 'different'],
        [array_replace($environment, ['DB_SSLMODE' => 'require']), 'exhibit'],
    ] as [$invalid, $name]) {
        try {
            nativeEnvironment($invalid, $name, []);
        } catch (RuntimeException | InvalidArgumentException) {
            continue;
        }
        throw new RuntimeException('Unsafe native configuration accepted.');
    }
};
$tests['Diagnostic command rejects missing flags before reading credentials or connecting'] = static function (): void {
    $process = proc_open(
        [PHP_BINARY, '-n', __DIR__ . '/../database/diagnose.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    check(is_resource($process), 'Could not start diagnostic CLI test.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 2 && $output === '' && str_contains($errors, 'Usage:'), 'Diagnostics require explicit target arguments.');
    $process = proc_open(
        [PHP_BINARY, '-n', __DIR__ . '/../database/diagnose.php', '--database=expected'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['DATABASE_URL' => 'invalid-secret-sentinel']
    );
    check(is_resource($process), 'Could not start diagnostic failure test.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 1 && str_contains($errors, 'FAIL:'), 'Diagnostic failures must exit nonzero.');
    check(!str_contains($output . $errors, 'invalid-secret-sentinel'), 'Diagnostic failure disclosed its input.');
};

if ($live) {
    require __DIR__ . '/validate-live.php';
    $tests['Explicit read-only PostgreSQL connection and installed schema'] = 'validateLiveDatabase';
}

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL: $name: " . $error->getMessage() . "\n");
    }
}
echo count($tests) . " tests, $failed failures.\n";
echo $live ? "Live checks used read-only queries; no migration was executed.\n" : "Offline only: no database connections or migrations executed.\n";
exit($failed === 0 ? 0 : 1);
