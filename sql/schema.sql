-- ShopEase database schema (MySQL 5.7+/8.x, InnoDB, utf8mb4)
CREATE DATABASE IF NOT EXISTS shopease CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE shopease;

CREATE TABLE IF NOT EXISTS users (
    user_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name      VARCHAR(120) NOT NULL,
    email          VARCHAR(190) NOT NULL,
    phone          CHAR(10)     NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    address        VARCHAR(255) NULL,
    city           VARCHAR(80)  NULL,
    pincode        CHAR(6)      NULL,
    is_verified    TINYINT(1)   NOT NULL DEFAULT 0,
    otp_code       VARCHAR(255) NULL,            -- stored as a hash, never plain text
    otp_expires_at DATETIME     NULL,
    otp_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    role           ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    product_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id    INT UNSIGNED NOT NULL,
    name           VARCHAR(200) NOT NULL,
    description    TEXT NULL,
    specifications TEXT NULL,                    -- one "Key: Value" per line
    mrp_price      DECIMAL(10,2) NOT NULL,
    selling_price  DECIMAL(10,2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    image_path     VARCHAR(255) NULL,
    rating         DECIMAL(2,1) NOT NULL DEFAULT 4.0,
    rating_count   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_products_category (category_id),
    KEY idx_products_name (name),
    KEY idx_products_stock (stock_quantity),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_images (
    image_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    KEY idx_pi_product (product_id),
    CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    order_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    total_amount     DECIMAL(10,2) NOT NULL,
    items_total      DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_charge  DECIMAL(10,2) NOT NULL DEFAULT 0,
    gst_amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method   ENUM('Razorpay','COD') NOT NULL,
    payment_status   ENUM('Pending','Paid','Failed','COD') NOT NULL DEFAULT 'Pending',
    order_status     ENUM('Pending','Shipped','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
    shipping_name    VARCHAR(120) NOT NULL,
    shipping_phone   CHAR(10) NOT NULL,
    shipping_address TEXT NOT NULL,              -- full formatted address
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (order_status),
    KEY idx_orders_created (created_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    order_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT UNSIGNED NOT NULL,
    product_id    INT UNSIGNED NOT NULL,
    quantity      INT UNSIGNED NOT NULL,
    price         DECIMAL(10,2) NOT NULL,        -- unit price at time of purchase
    KEY idx_oi_order (order_id),
    KEY idx_oi_product (product_id),
    CONSTRAINT fk_oi_order   FOREIGN KEY (order_id)   REFERENCES orders(order_id)     ON DELETE CASCADE,
    CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    payment_id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id              INT UNSIGNED NOT NULL,
    gateway_order_id      VARCHAR(100) NULL,     -- Razorpay order_xxx
    transaction_reference VARCHAR(100) NULL,     -- Razorpay pay_xxx
    payment_gateway       ENUM('Razorpay','COD') NOT NULL,
    amount                DECIMAL(10,2) NOT NULL,
    status                ENUM('Created','Success','Failed','Pending') NOT NULL DEFAULT 'Created',
    payment_date          DATETIME NULL,
    UNIQUE KEY uq_payments_gateway_order (gateway_order_id),
    KEY idx_payments_order (order_id),
    KEY idx_payments_txn (transaction_reference),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Sample catalogue (images optional; a placeholder is shown when image_path is NULL)
INSERT INTO categories (name, slug) VALUES
 ('Electronics','electronics'),('Fashion','fashion'),('Home & Kitchen','home-kitchen'),
 ('Books','books'),('Sports & Fitness','sports-fitness');

INSERT INTO products (category_id,name,description,specifications,mrp_price,selling_price,stock_quantity,rating,rating_count) VALUES
 (1,'Wireless Bluetooth Earbuds','True wireless earbuds with 30-hour battery life, noise isolation and IPX5 water resistance.','Battery: 30 hours\nConnectivity: Bluetooth 5.3\nWater Resistance: IPX5\nWarranty: 1 Year',2999,1299,50,4.3,1842),
 (1,'Smart Watch Pro 1.8"','AMOLED display smart watch with heart-rate, SpO2, sleep tracking and 7-day battery.','Display: 1.8 inch AMOLED\nBattery: 7 days\nSensors: HR, SpO2\nWarranty: 1 Year',5999,2499,35,4.1,967),
 (1,'20000 mAh Power Bank','Fast-charging 22.5W power bank with dual USB output and Type-C input.','Capacity: 20000 mAh\nOutput: 22.5W\nPorts: 2 USB-A, 1 Type-C\nWarranty: 6 Months',2499,1599,8,4.4,3120),
 (2,'Men''s Cotton Casual Shirt','Slim-fit pure cotton shirt, breathable and easy to maintain.','Fabric: 100% Cotton\nFit: Slim\nWash Care: Machine wash',1499,599,120,4.0,721),
 (2,'Women''s Running Shoes','Lightweight cushioned running shoes with breathable mesh upper.','Upper: Mesh\nSole: EVA\nUse: Running, Gym',2999,1499,60,4.2,544),
 (3,'Stainless Steel Pressure Cooker 5L','Induction-base 5 litre pressure cooker with safety valve.','Capacity: 5 Litre\nMaterial: Stainless Steel\nBase: Induction Friendly\nWarranty: 5 Years',2899,1899,25,4.5,2210),
 (3,'LED Table Lamp with USB Charging','Dimmable study lamp with three colour modes and touch control.','Modes: 3\nPower: 5W\nInput: USB',999,449,0,3.9,312),
 (4,'Atomic Habits (Paperback)','An easy and proven way to build good habits and break bad ones.','Author: James Clear\nLanguage: English\nPages: 320',799,399,200,4.7,9540),
 (5,'Yoga Mat 6mm Anti-Skid','Extra-thick anti-skid yoga mat with carry strap.','Thickness: 6 mm\nMaterial: NBR\nSize: 183 x 61 cm',1299,499,75,4.2,1180),
 (5,'Adjustable Dumbbell Set 20kg','Adjustable dumbbell set with anti-rust plates and secure collars.','Weight: 20 kg\nMaterial: Cast Iron\nIncludes: 2 handles, 8 plates',3999,2699,15,4.3,402);
