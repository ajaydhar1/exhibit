CREATE TABLE public.users (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email text NOT NULL CHECK (btrim(email) <> '' AND email = btrim(email)),
    password_hash text NOT NULL CHECK (btrim(password_hash) <> ''),
    display_name text NOT NULL CHECK (btrim(display_name) <> ''),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX users_email_lower_unique ON public.users (lower(email));

CREATE TABLE public.exhibitions (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    owner_id bigint NOT NULL REFERENCES public.users (id) ON DELETE RESTRICT,
    slug text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    title text NOT NULL CHECK (btrim(title) <> ''),
    subtitle text,
    summary text,
    description text,
    category text,
    cover jsonb CHECK (jsonb_typeof(cover) = 'object'),
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
    published_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT exhibitions_publication_state CHECK (
        (status = 'draft' AND published_at IS NULL)
        OR (status = 'published' AND published_at IS NOT NULL)
    )
);

CREATE INDEX exhibitions_owner_id_index ON public.exhibitions (owner_id);

CREATE TABLE public.chapters (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    exhibition_id bigint NOT NULL REFERENCES public.exhibitions (id) ON DELETE CASCADE,
    position integer NOT NULL CHECK (position > 0),
    title text NOT NULL CHECK (btrim(title) <> ''),
    summary text,
    era text,
    eyebrow text,
    intro text,
    ending_heading text,
    ending_text text,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chapters_exhibition_position_unique
        UNIQUE (exhibition_id, position) DEFERRABLE INITIALLY IMMEDIATE
);

CREATE TABLE public.content_blocks (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chapter_id bigint NOT NULL REFERENCES public.chapters (id) ON DELETE CASCADE,
    position integer NOT NULL CHECK (position > 0),
    type text NOT NULL CHECK (type IN ('text', 'figure', 'artifact')),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT content_blocks_chapter_position_unique
        UNIQUE (chapter_id, position) DEFERRABLE INITIALLY IMMEDIATE
);

CREATE FUNCTION public.exhibit_set_updated_at() RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    NEW.updated_at = statement_timestamp();
    RETURN NEW;
END;
$$;

CREATE TRIGGER users_set_updated_at
    BEFORE UPDATE ON public.users
    FOR EACH ROW EXECUTE FUNCTION public.exhibit_set_updated_at();

CREATE TRIGGER exhibitions_set_updated_at
    BEFORE UPDATE ON public.exhibitions
    FOR EACH ROW EXECUTE FUNCTION public.exhibit_set_updated_at();

CREATE TRIGGER chapters_set_updated_at
    BEFORE UPDATE ON public.chapters
    FOR EACH ROW EXECUTE FUNCTION public.exhibit_set_updated_at();

CREATE TRIGGER content_blocks_set_updated_at
    BEFORE UPDATE ON public.content_blocks
    FOR EACH ROW EXECUTE FUNCTION public.exhibit_set_updated_at();
