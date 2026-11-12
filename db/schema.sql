-- Language Hub — PostgreSQL schema (Neon).
-- Column names match the original MySQL tables so data imports one-to-one.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS roles (
  id   smallint PRIMARY KEY,
  name text NOT NULL UNIQUE
);
INSERT INTO roles (id, name) VALUES
  (1,'student'),(2,'teacher'),(3,'ta'),(4,'moderator'),(5,'ops'),(6,'admin')
ON CONFLICT (id) DO NOTHING;

CREATE TABLE IF NOT EXISTS users (
  id            serial PRIMARY KEY,
  name          varchar(100) NOT NULL,
  username      varchar(80)  UNIQUE,
  phone         varchar(15)  UNIQUE,
  email         varchar(150) UNIQUE,
  password_hash varchar(255),
  role_id       smallint NOT NULL DEFAULT 1 REFERENCES roles(id),
  avatar        varchar(500),
  bio           text,
  address       varchar(500),
  is_active     boolean NOT NULL DEFAULT true,
  is_verified   boolean NOT NULL DEFAULT false,
  last_login    timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS users_role_idx ON users(role_id);

-- Signed-in devices (max 2 per account). The cookie holds the token.
CREATE TABLE IF NOT EXISTS user_sessions (
  id           serial PRIMARY KEY,
  user_id      int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash   char(64) NOT NULL UNIQUE,
  device_name  varchar(100),
  ip           varchar(45),
  created_at   timestamptz NOT NULL DEFAULT now(),
  last_seen_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS user_sessions_user_idx ON user_sessions(user_id);

-- One-time email login codes (hashed).
CREATE TABLE IF NOT EXISTS email_codes (
  id         serial PRIMARY KEY,
  email      varchar(150) NOT NULL,
  code_hash  varchar(100) NOT NULL,
  tries      smallint NOT NULL DEFAULT 0,
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS email_codes_email_idx ON email_codes(email);

CREATE TABLE IF NOT EXISTS rate_limits (
  key        varchar(200) PRIMARY KEY,
  hits       int NOT NULL DEFAULT 0,
  window_end timestamptz NOT NULL
);

CREATE TABLE IF NOT EXISTS categories (
  id         serial PRIMARY KEY,
  name_bn    varchar(100) NOT NULL,
  name_en    varchar(100) NOT NULL,
  slug       varchar(100) NOT NULL UNIQUE,
  icon       varchar(50),
  color      varchar(20),
  sort_order smallint NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS teacher_profiles (
  id              serial PRIMARY KEY,
  user_id         int NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  designation     varchar(100),
  headline        varchar(255),
  bio             text,
  expertise       text,
  qualifications  text,
  intro_video_url varchar(500),
  fb_url          varchar(300),
  yt_url          varchar(300),
  website         varchar(300),
  rating_avg      numeric(3,2) NOT NULL DEFAULT 0,
  total_students  int NOT NULL DEFAULT 0,
  featured_order  smallint NOT NULL DEFAULT 0,
  updated_at      timestamptz
);

CREATE TABLE IF NOT EXISTS courses (
  id              serial PRIMARY KEY,
  title_bn        varchar(200) NOT NULL,
  title_en        varchar(200),
  slug            varchar(220) NOT NULL UNIQUE,
  short_desc      varchar(300),
  description_bn  text,
  what_you_learn  text,
  requirements    text,
  teacher_id      int REFERENCES teacher_profiles(id) ON DELETE SET NULL,
  category_id     int NOT NULL REFERENCES categories(id),
  price           numeric(10,2) NOT NULL DEFAULT 0,
  sale_price      numeric(10,2),
  type            varchar(10) NOT NULL DEFAULT 'recorded' CHECK (type IN ('recorded','live','hybrid')),
  level           varchar(15) NOT NULL DEFAULT 'beginner' CHECK (level IN ('beginner','intermediate','advanced')),
  language        varchar(50) NOT NULL DEFAULT 'Bangla',
  intro_video_url varchar(500),
  thumbnail       varchar(500),
  fb_group_link   varchar(500),
  total_lessons   int NOT NULL DEFAULT 0,
  total_duration  int NOT NULL DEFAULT 0,
  batch_start     date,
  batch_end       date,
  max_students    int NOT NULL DEFAULT 20,
  enrolled_count  int NOT NULL DEFAULT 0,
  rating_avg      numeric(3,2) NOT NULL DEFAULT 0,
  rating_count    int NOT NULL DEFAULT 0,
  is_featured     boolean NOT NULL DEFAULT false,
  is_free         boolean NOT NULL DEFAULT false,
  status          varchar(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS courses_cat_idx ON courses(category_id);
CREATE INDEX IF NOT EXISTS courses_teacher_idx ON courses(teacher_id);

CREATE TABLE IF NOT EXISTS modules (
  id         serial PRIMARY KEY,
  course_id  int NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  title      varchar(200) NOT NULL,
  sort_order smallint NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS lessons (
  id           serial PRIMARY KEY,
  module_id    int NOT NULL REFERENCES modules(id) ON DELETE CASCADE,
  title        varchar(200) NOT NULL,
  kind         varchar(10) NOT NULL DEFAULT 'video' CHECK (kind IN ('video','pdf','live','quiz','text')),
  video_url    varchar(500),
  pdf_url      varchar(500),
  content      text,
  duration     int NOT NULL DEFAULT 0,
  sort_order   smallint NOT NULL DEFAULT 0,
  is_free      boolean NOT NULL DEFAULT false,
  is_published boolean NOT NULL DEFAULT true
);

CREATE TABLE IF NOT EXISTS coupons (
  id         serial PRIMARY KEY,
  code       varchar(50) NOT NULL UNIQUE,
  type       varchar(10) NOT NULL DEFAULT 'percent' CHECK (type IN ('percent','flat')),
  value      numeric(10,2) NOT NULL DEFAULT 0,
  min_order  numeric(10,2) NOT NULL DEFAULT 0,
  max_uses   int NOT NULL DEFAULT 0,
  used_count int NOT NULL DEFAULT 0,
  expires_at date,
  is_active  boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS orders (
  id            serial PRIMARY KEY,
  invoice_no    varchar(30) UNIQUE,
  user_id       int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subtotal      numeric(10,2) NOT NULL,
  discount      numeric(10,2) NOT NULL DEFAULT 0,
  total         numeric(10,2) NOT NULL,
  coupon_code   varchar(50),
  status        varchar(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','paid','failed','refunded','cancelled')),
  billing_name  varchar(120),
  billing_phone varchar(20),
  billing_email varchar(150),
  pay_method    varchar(20),
  pay_ref       varchar(100),
  created_at    timestamptz NOT NULL DEFAULT now(),
  paid_at       timestamptz
);
CREATE INDEX IF NOT EXISTS orders_user_idx ON orders(user_id);

CREATE TABLE IF NOT EXISTS order_items (
  id        serial PRIMARY KEY,
  order_id  int NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  course_id int REFERENCES courses(id) ON DELETE SET NULL,
  title     varchar(200) NOT NULL,
  price     numeric(10,2) NOT NULL
);

CREATE TABLE IF NOT EXISTS enrollments (
  id           serial PRIMARY KEY,
  user_id      int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  course_id    int NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  order_id     int REFERENCES orders(id) ON DELETE SET NULL,
  status       varchar(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','expired','refunded','suspended')),
  progress_pct smallint NOT NULL DEFAULT 0,
  enrolled_at  timestamptz NOT NULL DEFAULT now(),
  completed_at timestamptz,
  UNIQUE (user_id, course_id)
);

CREATE TABLE IF NOT EXISTS lesson_progress (
  id            serial PRIMARY KEY,
  enrollment_id int NOT NULL REFERENCES enrollments(id) ON DELETE CASCADE,
  lesson_id     int NOT NULL REFERENCES lessons(id) ON DELETE CASCADE,
  completed     boolean NOT NULL DEFAULT false,
  completed_at  timestamptz,
  UNIQUE (enrollment_id, lesson_id)
);

CREATE TABLE IF NOT EXISTS reviews (
  id          serial PRIMARY KEY,
  course_id   int NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  user_id     int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  rating      smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body        text,
  is_approved boolean NOT NULL DEFAULT true,
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (course_id, user_id)
);

CREATE TABLE IF NOT EXISTS testimonials (
  id          serial PRIMARY KEY,
  name        varchar(255) NOT NULL,
  batch       varchar(255) NOT NULL DEFAULT '',
  text        text NOT NULL,
  rating      smallint NOT NULL DEFAULT 5,
  is_approved boolean NOT NULL DEFAULT true,
  sort_order  int NOT NULL DEFAULT 0,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS notices (
  id         serial PRIMARY KEY,
  author_id  int REFERENCES users(id) ON DELETE SET NULL,
  course_id  int REFERENCES courses(id) ON DELETE CASCADE,
  title      varchar(200),
  body       text NOT NULL,
  link_url   varchar(500),
  is_pinned  boolean NOT NULL DEFAULT false,
  status     varchar(10) NOT NULL DEFAULT 'published' CHECK (status IN ('published','draft','archived')),
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS notifications (
  id         serial PRIMARY KEY,
  user_id    int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind       varchar(50) NOT NULL,
  title      varchar(200) NOT NULL,
  body       text,
  action_url varchar(500),
  read_at    timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS notifications_user_idx ON notifications(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS contact_messages (
  id         serial PRIMARY KEY,
  name       varchar(150) NOT NULL,
  email      varchar(190) NOT NULL,
  phone      varchar(50),
  subject    varchar(200),
  message    text NOT NULL,
  is_read    boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS support_tickets (
  id          serial PRIMARY KEY,
  user_id     int NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject     varchar(255) NOT NULL,
  body        text NOT NULL,
  status      varchar(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','replied','closed')),
  admin_reply text,
  replied_at  timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS settings (
  setting_key   varchar(100) PRIMARY KEY,
  setting_value text,
  updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS live_sessions (
  id               serial PRIMARY KEY,
  course_id        int NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  title            varchar(200) NOT NULL,
  scheduled_at     timestamptz NOT NULL,
  duration_minutes int NOT NULL DEFAULT 90,
  meeting_url      varchar(500),
  recording_url    varchar(500),
  status           varchar(10) NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','live','ended','cancelled')),
  created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS live_sessions_course_idx ON live_sessions(course_id, scheduled_at);

-- Teaching team: every teacher on a course (the lead is also courses.teacher_id).
CREATE TABLE IF NOT EXISTS course_teachers (
  course_id   int NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  teacher_id  int NOT NULL REFERENCES teacher_profiles(id) ON DELETE CASCADE,
  role        varchar(60) NOT NULL DEFAULT 'Instructor',
  sort_order  int NOT NULL DEFAULT 0,
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (course_id, teacher_id)
);
CREATE INDEX IF NOT EXISTS course_teachers_teacher_idx ON course_teachers(teacher_id);
INSERT INTO course_teachers (course_id, teacher_id, role)
  SELECT id, teacher_id, 'Lead instructor' FROM courses WHERE teacher_id IS NOT NULL
  ON CONFLICT DO NOTHING;

-- Extra course details the admin/teacher can add any number of:
--   fact = "at a glance" item (label + value), section = titled card, faq = question + answer.
CREATE TABLE IF NOT EXISTS course_details (
  id          serial PRIMARY KEY,
  course_id   int NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  kind        varchar(10) NOT NULL DEFAULT 'section' CHECK (kind IN ('fact','section','faq')),
  icon        varchar(40),
  title       varchar(200) NOT NULL,
  body        text,
  sort_order  int NOT NULL DEFAULT 0,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS course_details_course_idx ON course_details(course_id, kind, sort_order);
