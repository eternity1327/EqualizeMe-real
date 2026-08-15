-- Create a limited database account for the application
--
-- Run in phpMyAdmin (SQL tab). CHANGE THE PASSWORD FIRST — pick something
-- long and random; you'll paste it into api/config.local.php and never
-- type it again.
--
--
-- WHY NOT JUST USE root?
--
-- The app currently connects as MySQL's `root` with an empty password,
-- XAMPP's default. Two problems:
--
--   1. Anyone who can reach port 3306 is root. That's fine while MySQL
--      only listens on localhost, and catastrophic the first time it
--      doesn't — a tunnel, a port-forward, or shared hosting. Root can
--      usually read and write files on the server too, so it isn't only
--      the data at stake.
--
--   2. Least privilege. This application reads and writes a handful of
--      tables. It never needs to drop the schema, create users, or grant
--      permissions. Running with rights it doesn't need means any future
--      bug — a SQL injection that slips past the prepared statements, a
--      mistaken query — inherits all of them.
--
-- The account below can read and write the application's data and do
-- nothing else. It cannot DROP, CREATE, ALTER or GRANT.


-- ---------------------------------------------------------------------
-- 1. Create the account
-- ---------------------------------------------------------------------
-- 'localhost' restricts it to connections from this machine. Change to
-- '%' only if the app and database end up on different hosts, and pair
-- that with a firewall rule.

CREATE USER IF NOT EXISTS 'equalizeme_app'@'localhost'
    IDENTIFIED BY 'CHANGE-THIS-TO-SOMETHING-LONG-AND-RANDOM';


-- ---------------------------------------------------------------------
-- 2. Grant only what the application uses
-- ---------------------------------------------------------------------
-- SELECT, INSERT, UPDATE and DELETE cover everything the code does:
-- reading and writing users, profiles, settings, IEMs and reset tokens.
--
-- Deliberately NOT granted:
--   DROP, ALTER, CREATE  — schema changes are a human task, done in
--                          phpMyAdmin, not something the app should be
--                          able to do by accident or under attack
--   FILE                 — reading/writing server files
--   GRANT OPTION         — handing out permissions
--   SUPER, PROCESS       — administrative access

GRANT SELECT, INSERT, UPDATE, DELETE
    ON equalizeme.*
    TO 'equalizeme_app'@'localhost';

FLUSH PRIVILEGES;


-- ---------------------------------------------------------------------
-- 3. Put the credentials in api/config.local.php
-- ---------------------------------------------------------------------
--     'database' => [
--         'host'     => '127.0.0.1',
--         'name'     => 'equalizeme',
--         'user'     => 'equalizeme_app',
--         'password' => 'the password you chose above',
--     ],
--
-- That file is gitignored, so the password stays out of the repository.
--
-- For the Python service, set the same values as environment variables
-- before starting it:
--
--     $env:DB_USER = "equalizeme_app"
--     $env:DB_PASSWORD = "the password you chose above"
--     python ai_service.py


-- ---------------------------------------------------------------------
-- 4. Then set a password on root as well
-- ---------------------------------------------------------------------
-- Creating a limited account doesn't help if root is still open. In
-- XAMPP: phpMyAdmin -> User accounts -> root -> Change password.
--
-- Note this breaks phpMyAdmin until you update its stored credentials in
-- xampp\phpMyAdmin\config.inc.php:
--
--     $cfg['Servers'][$i]['password'] = 'your new root password';
--
-- Do this when you have a few minutes, not right before a demo.


-- ---------------------------------------------------------------------
-- To undo
-- ---------------------------------------------------------------------
--     DROP USER 'equalizeme_app'@'localhost';
-- and remove the 'database' block from config.local.php to fall back to
-- the defaults.
