CREATE TABLE IF NOT EXISTS admin_credentials (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(100) NOT NULL
);

INSERT INTO admin_credentials (email, password, role)
VALUES ('admin@example.com', 'changeme123', 'super_admin');
