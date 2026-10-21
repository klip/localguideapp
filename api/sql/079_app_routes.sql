-- The app's routes, managed in the CMS (docs/auth-cms-plan.md, phase 7):
-- what the Vue app builds its router from at start-up (`appMap`,
-- `lib/AppMap.php`) and their access rules. Plus which UI-text blocks each
-- page shows — developer-owned (pages' layout is the code's, not the CMS's),
-- used to preload a page's text and to draw the content editor's wireframe.
--
-- app_routes        one row per route. `kind`: page (renders `component`, a
--                   page the app's code registers — src/router/pages.ts),
--                   redirect (to `redirect`), shell (the frame around every
--                   page; one row, `_shell`, holding the header/footer
--                   blocks). `access` public | signed_in | signed_out and
--                   `role` (guest | guide, NULL = either) are enforced by the
--                   app's router. `system` routes can't be removed or
--                   disabled. `pass_params` = vue-router's `props: true`.
-- app_route_blocks  route × text block (`page.widget`, e.g. `discover.deck`)
--                   with its place: header, main, shared, footer, always.
--                   Changed only by migrations; `yarn i18n:check` (client)
--                   prints the SQL when the code uses a block not listed.
--
-- Seeded with the app's routes as of 2026-10-21 and the blocks its code used
-- then. Re-runnable (the seed inserts only what's missing).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `app_routes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL,
  `kind` ENUM('page', 'redirect', 'shell') NOT NULL DEFAULT 'page',
  `path` VARCHAR(160) NULL,
  `component` VARCHAR(60) NULL,
  `redirect` VARCHAR(160) NULL,
  `title` VARCHAR(80) NOT NULL,
  `pass_params` TINYINT(1) NOT NULL DEFAULT 0,
  `access` ENUM('public', 'signed_in', 'signed_out') NOT NULL DEFAULT 'public',
  `role` ENUM('guest', 'guide') NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `system` TINYINT(1) NOT NULL DEFAULT 0,
  `position` SMALLINT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(190) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_routes_name` (`name`),
  UNIQUE KEY `app_routes_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_route_blocks` (
  `route_id` INT UNSIGNED NOT NULL,
  `prefix` VARCHAR(120) NOT NULL,
  `area` ENUM('header', 'main', 'shared', 'footer', 'always') NOT NULL,
  `position` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`route_id`, `prefix`),
  CONSTRAINT `fk_app_route_blocks_route` FOREIGN KEY (`route_id`) REFERENCES `app_routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_routes` (`name`, `kind`, `path`, `component`, `redirect`, `title`, `pass_params`, `system`, `position`) VALUES
  ('_shell', 'shell', NULL, NULL, NULL, 'Every page', 0, 1, 0),
  ('root', 'redirect', '/', NULL, '/discover', 'Start (→ Discover)', 0, 1, 10),
  ('home', 'page', '/about', 'HomePage', NULL, 'About', 0, 0, 20),
  ('register', 'page', '/register', 'RegisterPage', NULL, 'Register', 0, 0, 30),
  ('login', 'page', '/login', 'LoginPage', NULL, 'Log in', 0, 1, 40),
  ('forgot-password', 'page', '/forgot-password', 'ForgotPasswordPage', NULL, 'Forgot password', 0, 0, 50),
  ('reset-password', 'page', '/reset-password', 'ResetPasswordPage', NULL, 'Reset password', 0, 0, 60),
  ('secure-account', 'page', '/secure-account', 'SecureAccountPage', NULL, 'Secure account', 0, 0, 70),
  ('unlock', 'page', '/unlock', 'UnlockPage', NULL, 'Unlock picks', 0, 0, 80),
  ('onboarding', 'redirect', '/onboarding', NULL, '/profile', 'Onboarding (→ Profile)', 0, 0, 90),
  ('discover', 'page', '/discover', 'DiscoverPage', NULL, 'Discover', 0, 1, 100),
  ('guide-profile', 'page', '/guides/:id', 'GuideProfilePage', NULL, 'Guide profile', 1, 0, 110),
  ('visitor-profile', 'page', '/visitors/:id', 'VisitorProfilePage', NULL, 'Traveller profile', 1, 0, 120),
  ('guide-discover', 'page', '/guide/discover', 'GuideDiscoverPage', NULL, 'Discover (guide)', 0, 0, 130),
  ('matches', 'page', '/matches', 'MatchesPage', NULL, 'Matches', 0, 0, 140),
  ('trips', 'page', '/trips', 'TripsPage', NULL, 'My trips', 0, 0, 150),
  ('messages', 'page', '/messages/:id?', 'MessagesPage', NULL, 'Messages', 1, 0, 160),
  ('planner', 'page', '/planner', 'PlannerPage', NULL, 'Trip planner', 0, 0, 170),
  ('profile', 'page', '/profile', 'ProfilePage', NULL, 'Profile', 0, 0, 180),
  ('not-found', 'page', '/:pathMatch(.*)*', 'NotFoundPage', NULL, 'Not found', 0, 1, 190);

INSERT IGNORE INTO `app_route_blocks` (`route_id`, `prefix`, `area`, `position`)
SELECT r.`id`, b.`prefix`, b.`area`, b.`position` FROM (
  SELECT '_shell' AS `route`, 'common.app_header' AS `prefix`, 'header' AS `area`, 0 AS `position`
UNION ALL
  SELECT '_shell', 'common.language_switcher', 'header', 1
UNION ALL
  SELECT '_shell', 'common.app_footer', 'footer', 0
UNION ALL
  SELECT '_shell', 'common.cookie_notice', 'footer', 1
UNION ALL
  SELECT '_shell', 'common.api', 'always', 0
UNION ALL
  SELECT '_shell', 'common.chat', 'always', 1
UNION ALL
  SELECT '_shell', 'common.message_toaster', 'always', 2
UNION ALL
  SELECT '_shell', 'common.messages', 'always', 3
UNION ALL
  SELECT '_shell', 'common.modal_dialog', 'always', 4
UNION ALL
  SELECT '_shell', 'common.terms_dialog', 'always', 5
UNION ALL
  SELECT 'home', 'home.hero', 'main', 0
UNION ALL
  SELECT 'home', 'home.how', 'main', 1
UNION ALL
  SELECT 'home', 'home.pricing', 'main', 2
UNION ALL
  SELECT 'home', 'common.phone_preview', 'shared', 0
UNION ALL
  SELECT 'home', 'common.price_box', 'shared', 1
UNION ALL
  SELECT 'register', 'register.header', 'main', 0
UNION ALL
  SELECT 'register', 'register.form', 'main', 1
UNION ALL
  SELECT 'register', 'register.terms', 'main', 2
UNION ALL
  SELECT 'register', 'register.toast', 'main', 3
UNION ALL
  SELECT 'register', 'common.form', 'shared', 0
UNION ALL
  SELECT 'login', 'login.header', 'main', 0
UNION ALL
  SELECT 'login', 'login.code', 'main', 1
UNION ALL
  SELECT 'login', 'login.form', 'main', 2
UNION ALL
  SELECT 'login', 'login.toast', 'main', 3
UNION ALL
  SELECT 'login', 'common.form', 'shared', 0
UNION ALL
  SELECT 'forgot-password', 'forgot_password.header', 'main', 0
UNION ALL
  SELECT 'forgot-password', 'forgot_password.sent', 'main', 1
UNION ALL
  SELECT 'forgot-password', 'forgot_password.form', 'main', 2
UNION ALL
  SELECT 'forgot-password', 'forgot_password.toast', 'main', 3
UNION ALL
  SELECT 'forgot-password', 'common.form', 'shared', 0
UNION ALL
  SELECT 'reset-password', 'reset_password.header', 'main', 0
UNION ALL
  SELECT 'reset-password', 'reset_password.dead_link', 'main', 1
UNION ALL
  SELECT 'reset-password', 'reset_password.form', 'main', 2
UNION ALL
  SELECT 'reset-password', 'reset_password.toast', 'main', 3
UNION ALL
  SELECT 'secure-account', 'secure_account.header', 'main', 0
UNION ALL
  SELECT 'secure-account', 'secure_account.failed', 'main', 1
UNION ALL
  SELECT 'secure-account', 'secure_account.working', 'main', 2
UNION ALL
  SELECT 'secure-account', 'secure_account.toast', 'main', 3
UNION ALL
  SELECT 'unlock', 'unlock.header', 'main', 0
UNION ALL
  SELECT 'unlock', 'unlock.panel', 'main', 1
UNION ALL
  SELECT 'unlock', 'unlock.price', 'main', 2
UNION ALL
  SELECT 'unlock', 'unlock.toast', 'main', 3
UNION ALL
  SELECT 'unlock', 'common.price_box', 'shared', 0
UNION ALL
  SELECT 'unlock', 'common.protected_notice', 'shared', 1
UNION ALL
  SELECT 'discover', 'discover.filters', 'main', 0
UNION ALL
  SELECT 'discover', 'discover.deck', 'main', 1
UNION ALL
  SELECT 'discover', 'discover.counter', 'main', 2
UNION ALL
  SELECT 'discover', 'discover.empty', 'main', 3
UNION ALL
  SELECT 'discover', 'discover.rail', 'main', 4
UNION ALL
  SELECT 'discover', 'discover.auth_modal', 'main', 5
UNION ALL
  SELECT 'discover', 'discover.pay_modal', 'main', 6
UNION ALL
  SELECT 'discover', 'discover.toast', 'main', 7
UNION ALL
  SELECT 'discover', 'common.active_filters', 'shared', 0
UNION ALL
  SELECT 'discover', 'common.decision_row', 'shared', 1
UNION ALL
  SELECT 'discover', 'common.distance', 'shared', 2
UNION ALL
  SELECT 'discover', 'common.filter_panel', 'shared', 3
UNION ALL
  SELECT 'discover', 'common.filters', 'shared', 4
UNION ALL
  SELECT 'discover', 'common.gender', 'shared', 5
UNION ALL
  SELECT 'discover', 'common.guide_card', 'shared', 6
UNION ALL
  SELECT 'discover', 'common.level', 'shared', 7
UNION ALL
  SELECT 'discover', 'common.location_prompt', 'shared', 8
UNION ALL
  SELECT 'discover', 'common.place_search', 'shared', 9
UNION ALL
  SELECT 'discover', 'common.price_box', 'shared', 10
UNION ALL
  SELECT 'discover', 'common.profile_card', 'shared', 11
UNION ALL
  SELECT 'discover', 'common.protected_notice', 'shared', 12
UNION ALL
  SELECT 'discover', 'common.range_picker', 'shared', 13
UNION ALL
  SELECT 'discover', 'common.rating', 'shared', 14
UNION ALL
  SELECT 'discover', 'common.standing', 'shared', 15
UNION ALL
  SELECT 'discover', 'common.time_ago', 'shared', 16
UNION ALL
  SELECT 'guide-profile', 'guide_profile.page', 'main', 0
UNION ALL
  SELECT 'guide-profile', 'guide_profile.details', 'main', 1
UNION ALL
  SELECT 'guide-profile', 'guide_profile.not_found', 'main', 2
UNION ALL
  SELECT 'guide-profile', 'guide_profile.toast', 'main', 3
UNION ALL
  SELECT 'guide-profile', 'common.decision_row', 'shared', 0
UNION ALL
  SELECT 'guide-profile', 'common.filters', 'shared', 1
UNION ALL
  SELECT 'guide-profile', 'common.guide_card', 'shared', 2
UNION ALL
  SELECT 'guide-profile', 'common.level', 'shared', 3
UNION ALL
  SELECT 'guide-profile', 'common.rating', 'shared', 4
UNION ALL
  SELECT 'guide-profile', 'common.report_user', 'shared', 5
UNION ALL
  SELECT 'guide-profile', 'common.review_form', 'shared', 6
UNION ALL
  SELECT 'guide-profile', 'common.review_item', 'shared', 7
UNION ALL
  SELECT 'guide-profile', 'common.review_summary', 'shared', 8
UNION ALL
  SELECT 'guide-profile', 'common.reviews', 'shared', 9
UNION ALL
  SELECT 'guide-profile', 'common.reviews_dialog', 'shared', 10
UNION ALL
  SELECT 'guide-profile', 'common.standing', 'shared', 11
UNION ALL
  SELECT 'guide-profile', 'common.star_rating', 'shared', 12
UNION ALL
  SELECT 'guide-profile', 'common.time_ago', 'shared', 13
UNION ALL
  SELECT 'visitor-profile', 'visitor_profile.page', 'main', 0
UNION ALL
  SELECT 'visitor-profile', 'visitor_profile.details', 'main', 1
UNION ALL
  SELECT 'visitor-profile', 'visitor_profile.not_found', 'main', 2
UNION ALL
  SELECT 'visitor-profile', 'visitor_profile.toast', 'main', 3
UNION ALL
  SELECT 'visitor-profile', 'common.filters', 'shared', 0
UNION ALL
  SELECT 'visitor-profile', 'common.gender', 'shared', 1
UNION ALL
  SELECT 'visitor-profile', 'common.level', 'shared', 2
UNION ALL
  SELECT 'visitor-profile', 'common.rating', 'shared', 3
UNION ALL
  SELECT 'visitor-profile', 'common.report_user', 'shared', 4
UNION ALL
  SELECT 'visitor-profile', 'common.review_form', 'shared', 5
UNION ALL
  SELECT 'visitor-profile', 'common.review_item', 'shared', 6
UNION ALL
  SELECT 'visitor-profile', 'common.review_summary', 'shared', 7
UNION ALL
  SELECT 'visitor-profile', 'common.reviews', 'shared', 8
UNION ALL
  SELECT 'visitor-profile', 'common.reviews_dialog', 'shared', 9
UNION ALL
  SELECT 'visitor-profile', 'common.standing', 'shared', 10
UNION ALL
  SELECT 'visitor-profile', 'common.star_rating', 'shared', 11
UNION ALL
  SELECT 'visitor-profile', 'common.time_ago', 'shared', 12
UNION ALL
  SELECT 'visitor-profile', 'common.visitor_card', 'shared', 13
UNION ALL
  SELECT 'guide-discover', 'guide_discover.header', 'main', 0
UNION ALL
  SELECT 'guide-discover', 'guide_discover.filters', 'main', 1
UNION ALL
  SELECT 'guide-discover', 'guide_discover.deck', 'main', 2
UNION ALL
  SELECT 'guide-discover', 'guide_discover.empty', 'main', 3
UNION ALL
  SELECT 'guide-discover', 'guide_discover.rail', 'main', 4
UNION ALL
  SELECT 'guide-discover', 'guide_discover.toast', 'main', 5
UNION ALL
  SELECT 'guide-discover', 'common.active_filters', 'shared', 0
UNION ALL
  SELECT 'guide-discover', 'common.decision_row', 'shared', 1
UNION ALL
  SELECT 'guide-discover', 'common.distance', 'shared', 2
UNION ALL
  SELECT 'guide-discover', 'common.filter_panel', 'shared', 3
UNION ALL
  SELECT 'guide-discover', 'common.filters', 'shared', 4
UNION ALL
  SELECT 'guide-discover', 'common.gender', 'shared', 5
UNION ALL
  SELECT 'guide-discover', 'common.level', 'shared', 6
UNION ALL
  SELECT 'guide-discover', 'common.location_prompt', 'shared', 7
UNION ALL
  SELECT 'guide-discover', 'common.place_search', 'shared', 8
UNION ALL
  SELECT 'guide-discover', 'common.profile_card', 'shared', 9
UNION ALL
  SELECT 'guide-discover', 'common.range_picker', 'shared', 10
UNION ALL
  SELECT 'guide-discover', 'common.standing', 'shared', 11
UNION ALL
  SELECT 'guide-discover', 'common.visitor_card', 'shared', 12
UNION ALL
  SELECT 'matches', 'matches.header', 'main', 0
UNION ALL
  SELECT 'matches', 'matches.signed_out', 'main', 1
UNION ALL
  SELECT 'matches', 'matches.toolbar', 'main', 2
UNION ALL
  SELECT 'matches', 'matches.list', 'main', 3
UNION ALL
  SELECT 'matches', 'matches.empty', 'main', 4
UNION ALL
  SELECT 'matches', 'matches.tabs', 'main', 5
UNION ALL
  SELECT 'matches', 'matches.row', 'main', 6
UNION ALL
  SELECT 'matches', 'matches.actions', 'main', 7
UNION ALL
  SELECT 'matches', 'matches.toast', 'main', 8
UNION ALL
  SELECT 'matches', 'common.booking_panel', 'shared', 0
UNION ALL
  SELECT 'matches', 'common.booking_time', 'shared', 1
UNION ALL
  SELECT 'matches', 'common.level', 'shared', 2
UNION ALL
  SELECT 'matches', 'common.map_links', 'shared', 3
UNION ALL
  SELECT 'matches', 'common.protected_notice', 'shared', 4
UNION ALL
  SELECT 'trips', 'trips.header', 'main', 0
UNION ALL
  SELECT 'trips', 'trips.signed_out', 'main', 1
UNION ALL
  SELECT 'trips', 'trips.list', 'main', 2
UNION ALL
  SELECT 'trips', 'trips.empty', 'main', 3
UNION ALL
  SELECT 'trips', 'trips.card', 'main', 4
UNION ALL
  SELECT 'trips', 'trips.tabs', 'main', 5
UNION ALL
  SELECT 'trips', 'trips.toast', 'main', 6
UNION ALL
  SELECT 'trips', 'trips.status', 'main', 7
UNION ALL
  SELECT 'trips', 'common.booking_panel', 'shared', 0
UNION ALL
  SELECT 'trips', 'common.booking_time', 'shared', 1
UNION ALL
  SELECT 'trips', 'common.level', 'shared', 2
UNION ALL
  SELECT 'trips', 'common.map_links', 'shared', 3
UNION ALL
  SELECT 'trips', 'common.protected_notice', 'shared', 4
UNION ALL
  SELECT 'messages', 'messages.header', 'main', 0
UNION ALL
  SELECT 'messages', 'messages.signed_out', 'main', 1
UNION ALL
  SELECT 'messages', 'messages.list', 'main', 2
UNION ALL
  SELECT 'messages', 'messages.thread', 'main', 3
UNION ALL
  SELECT 'messages', 'messages.location', 'main', 4
UNION ALL
  SELECT 'messages', 'messages.toast', 'main', 5
UNION ALL
  SELECT 'planner', 'planner.page', 'main', 0
UNION ALL
  SELECT 'planner', 'planner.trips', 'main', 1
UNION ALL
  SELECT 'planner', 'planner.editor', 'main', 2
UNION ALL
  SELECT 'planner', 'planner.results', 'main', 3
UNION ALL
  SELECT 'planner', 'planner.card', 'main', 4
UNION ALL
  SELECT 'planner', 'planner.toast', 'main', 5
UNION ALL
  SELECT 'planner', 'common.distance', 'shared', 0
UNION ALL
  SELECT 'planner', 'common.guide_card', 'shared', 1
UNION ALL
  SELECT 'planner', 'common.level', 'shared', 2
UNION ALL
  SELECT 'planner', 'common.place_search', 'shared', 3
UNION ALL
  SELECT 'planner', 'common.rating', 'shared', 4
UNION ALL
  SELECT 'planner', 'common.standing', 'shared', 5
UNION ALL
  SELECT 'planner', 'common.time_ago', 'shared', 6
UNION ALL
  SELECT 'profile', 'profile.header', 'main', 0
UNION ALL
  SELECT 'profile', 'profile.add_role', 'main', 1
UNION ALL
  SELECT 'profile', 'profile.signed_out', 'main', 2
UNION ALL
  SELECT 'profile', 'profile.page', 'main', 3
UNION ALL
  SELECT 'profile', 'profile.contact', 'main', 4
UNION ALL
  SELECT 'profile', 'profile.password', 'main', 5
UNION ALL
  SELECT 'profile', 'profile.devices', 'main', 6
UNION ALL
  SELECT 'profile', 'profile.verification', 'main', 7
UNION ALL
  SELECT 'profile', 'profile.details', 'main', 8
UNION ALL
  SELECT 'profile', 'profile.toast', 'main', 9
UNION ALL
  SELECT 'profile', 'profile.level', 'main', 10
UNION ALL
  SELECT 'profile', 'common.attribute_combobox', 'shared', 0
UNION ALL
  SELECT 'profile', 'common.contact', 'shared', 1
UNION ALL
  SELECT 'profile', 'common.country', 'shared', 2
UNION ALL
  SELECT 'profile', 'common.gender', 'shared', 3
UNION ALL
  SELECT 'profile', 'common.image_cropper', 'shared', 4
UNION ALL
  SELECT 'profile', 'common.level', 'shared', 5
UNION ALL
  SELECT 'profile', 'common.place_search', 'shared', 6
UNION ALL
  SELECT 'profile', 'common.range_picker', 'shared', 7
UNION ALL
  SELECT 'profile', 'common.rating', 'shared', 8
UNION ALL
  SELECT 'profile', 'common.standing', 'shared', 9
UNION ALL
  SELECT 'profile', 'common.time_ago', 'shared', 10
UNION ALL
  SELECT 'not-found', 'not_found.panel', 'main', 0
) b JOIN `app_routes` r ON r.`name` = b.`route`;
