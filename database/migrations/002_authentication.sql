ALTER TABLE public.users
    ADD COLUMN email_verified_at timestamptz,
    ADD COLUMN session_version integer NOT NULL DEFAULT 1 CHECK (session_version > 0);

CREATE TABLE public.auth_tokens (
    token_hash text PRIMARY KEY CHECK (token_hash ~ '^[a-f0-9]{64}$'),
    user_id bigint NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    purpose text NOT NULL CHECK (purpose IN ('verify', 'reset')),
    expires_at timestamptz NOT NULL,
    UNIQUE (user_id, purpose)
);
CREATE INDEX auth_tokens_expiry_index ON public.auth_tokens (expires_at);

CREATE TABLE public.auth_rate_limits (
    bucket text PRIMARY KEY CHECK (bucket ~ '^[a-f0-9]{64}$'),
    hits integer NOT NULL CHECK (hits > 0),
    expires_at timestamptz NOT NULL
);
CREATE INDEX auth_rate_limits_expiry_index ON public.auth_rate_limits (expires_at);

CREATE TABLE public.auth_mail_queue (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    purpose text NOT NULL CHECK (purpose IN ('verify', 'reset')),
    payload text NOT NULL,
    attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamptz NOT NULL,
    UNIQUE (user_id, purpose)
);
CREATE INDEX auth_mail_queue_delivery_index ON public.auth_mail_queue (available_at);
