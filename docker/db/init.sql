SELECT 'CREATE DATABASE app_test OWNER ' || current_user
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'app_test')\gexec
