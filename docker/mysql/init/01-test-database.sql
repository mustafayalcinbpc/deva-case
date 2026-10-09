-- Testler ayrı bir veritabanında çalışır (phpunit.xml: DB_DATABASE=temizlik_test).
CREATE DATABASE IF NOT EXISTS temizlik_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON temizlik_test.* TO 'temizlik'@'%';
