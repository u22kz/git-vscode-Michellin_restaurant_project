-- =====================================================
-- BAZA DANYCH - MICHELIN RESTAURANT
-- =====================================================

CREATE DATABASE IF NOT EXISTS michelin_restaurant
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE michelin_restaurant;


-- =====================================================
-- 1. UŻYTKOWNICY
-- =====================================================

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(50) NOT NULL,
    surname VARCHAR(80) NOT NULL,

    customer_phone VARCHAR(20) NOT NULL,
    customer_email VARCHAR(100) NOT NULL UNIQUE,

    password VARCHAR(255) NOT NULL,

    role ENUM('client', 'employee', 'admin')
        NOT NULL DEFAULT 'client',

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =====================================================
-- 2. PRACOWNICY
-- =====================================================

CREATE TABLE employees (
    employee_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL UNIQUE,

    position VARCHAR(100) NOT NULL,
    description TEXT,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_employees_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);


-- =====================================================
-- 3. KATEGORIE I PODKATEGORIE
-- =====================================================

CREATE TABLE categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    parent_id INT UNSIGNED NULL,

    name VARCHAR(100) NOT NULL,

    slug VARCHAR(100) NOT NULL UNIQUE,

    description TEXT,

    sort_order INT NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id)
        REFERENCES categories(category_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);


-- =====================================================
-- 4. USŁUGI
-- =====================================================

CREATE TABLE services (
    service_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    category_id INT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,

    description TEXT,

    price DECIMAL(10,2) NOT NULL,

    unit VARCHAR(20) NOT NULL DEFAULT 'osoba',

    is_available BOOLEAN NOT NULL DEFAULT TRUE,

    duration_minutes SMALLINT UNSIGNED NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_services_price
        CHECK (price >= 0),

    CONSTRAINT chk_services_duration
        CHECK (duration_minutes > 0),

    CONSTRAINT fk_services_category
        FOREIGN KEY (category_id)
        REFERENCES categories(category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_services_category (category_id),

    INDEX idx_services_available (is_available)
);


-- =====================================================
-- 5. PRACOWNICY ↔ USŁUGI
-- RELACJA N:M
-- =====================================================

CREATE TABLE employee_services (
    employee_id INT UNSIGNED NOT NULL,

    service_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (employee_id, service_id),

    CONSTRAINT fk_employee_services_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_employee_services_service
        FOREIGN KEY (service_id)
        REFERENCES services(service_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    INDEX idx_employee_services_service (service_id)
);


-- =====================================================
-- 6. DOSTĘPNOŚĆ PRACOWNIKÓW
-- =====================================================

CREATE TABLE employee_availability (
    availability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    employee_id INT UNSIGNED NOT NULL,

    day_of_week TINYINT UNSIGNED NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    CONSTRAINT chk_availability_day
        CHECK (day_of_week BETWEEN 1 AND 7),

    CONSTRAINT chk_availability_hours
        CHECK (start_time < end_time),

    CONSTRAINT fk_availability_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    INDEX idx_availability_employee_day
        (employee_id, day_of_week)
);


-- =====================================================
-- 7. REZERWACJE
-- =====================================================

CREATE TABLE reservations (
    reservation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    employee_id INT UNSIGNED NOT NULL,

    service_id INT UNSIGNED NOT NULL,

    reservation_date DATE NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    guest_count INT UNSIGNED NOT NULL,

    status ENUM(
        'pending',
        'confirmed',
        'cancelled',
        'completed',
        'no_show'
    ) NOT NULL DEFAULT 'pending',

    comment TEXT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_reservations_time
        CHECK (start_time < end_time),

    CONSTRAINT chk_reservations_guests
        CHECK (guest_count > 0),

    CONSTRAINT fk_reservations_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_reservations_employee
        FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_reservations_service
        FOREIGN KEY (service_id)
        REFERENCES services(service_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    INDEX idx_reservations_user
        (user_id),

    INDEX idx_reservations_employee_date
        (employee_id, reservation_date),

    INDEX idx_reservations_service
        (service_id),

    INDEX idx_reservations_status
        (status),

    INDEX idx_reservations_date
        (reservation_date)
);