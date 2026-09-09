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
-- =====================================================
-- DANE TESTOWE
-- =====================================================


-- =====================================================
-- 1. UŻYTKOWNICY
-- =====================================================

INSERT INTO users
(user_id, name, surname, customer_phone, customer_email, password, role)
VALUES
(1, 'Jan', 'Kowalski', '500600700', 'jan.kowalski@example.com', 'TEST_HASH_1', 'client'),
(2, 'Anna', 'Nowak', '501602703', 'anna.nowak@example.com', 'TEST_HASH_2', 'client'),
(3, 'Piotr', 'Wiśniewski', '502603704', 'piotr.wisniewski@example.com', 'TEST_HASH_3', 'employee'),
(4, 'Maria', 'Wójcik', '503604705', 'maria.wojcik@example.com', 'TEST_HASH_4', 'employee'),
(5, 'Tomasz', 'Kaczmarek', '504605706', 'tomasz.kaczmarek@example.com', 'TEST_HASH_5', 'employee'),
(6, 'Adam', 'Zieliński', '505606707', 'adam.zielinski@example.com', 'TEST_HASH_6', 'admin');


-- =====================================================
-- 2. PRACOWNICY
-- =====================================================

INSERT INTO employees
(employee_id, user_id, position, description)
VALUES
(1, 3, 'Szef kuchni',
 'Odpowiada za przygotowanie menu degustacyjnego.'),

(2, 4, 'Sommelier',
 'Odpowiada za dobór win i obsługę wine pairingu.'),

(3, 5, 'Manager restauracji',
 'Odpowiada za organizację rezerwacji i obsługę gości.');


-- =====================================================
-- 3. KATEGORIE GŁÓWNE
-- =====================================================

INSERT INTO categories
(category_id, parent_id, name, slug, description, sort_order)
VALUES
(1, NULL, 'Menu degustacyjne', 'menu-degustacyjne',
 'Menu przygotowywane przez szefa kuchni.', 1),

(2, NULL, 'Napoje', 'napoje',
 'Napoje dostępne w restauracji.', 2),

(3, NULL, 'Usługi restauracyjne', 'uslugi-restauracyjne',
 'Dodatkowe usługi oferowane przez restaurację.', 3);


-- =====================================================
-- 4. PODKATEGORIE
-- =====================================================

INSERT INTO categories
(category_id, parent_id, name, slug, description, sort_order)
VALUES
(4, 1, 'Menu sezonowe', 'menu-sezonowe',
 'Menu oparte na sezonowych składnikach.', 1),

(5, 1, 'Menu specjalne', 'menu-specjalne',
 'Specjalne propozycje szefa kuchni.', 2),

(6, 2, 'Wina', 'wina',
 'Starannie wybrane wina.', 1),

(7, 2, 'Napoje bezalkoholowe', 'napoje-bezalkoholowe',
 'Napoje bezalkoholowe.', 2),

(8, 3, 'Sala prywatna', 'sala-prywatna',
 'Rezerwacja prywatnej przestrzeni.', 1);


-- =====================================================
-- 5. USŁUGI
-- =====================================================

INSERT INTO services
(service_id, category_id, name, description, price, unit,
 is_available, duration_minutes)
VALUES
(1, 4, 'Menu degustacyjne 5 dań',
 'Sezonowe menu degustacyjne składające się z pięciu dań.',
 350.00, 'osoba', TRUE, 120),

(2, 4, 'Menu degustacyjne 7 dań',
 'Sezonowe menu degustacyjne składające się z siedmiu dań.',
 490.00, 'osoba', TRUE, 150),

(3, 5, 'Kolacja specjalna szefa kuchni',
 'Autorskie menu przygotowane przez szefa kuchni.',
 650.00, 'osoba', TRUE, 180),

(4, 6, 'Wine pairing',
 'Dobór win do kolejnych dań menu degustacyjnego.',
 250.00, 'osoba', TRUE, 120),

(5, 7, 'Menu bezalkoholowe',
 'Degustacyjne napoje bezalkoholowe.',
 150.00, 'osoba', TRUE, 90),

(6, 8, 'Rezerwacja sali prywatnej',
 'Możliwość zorganizowania kolacji w prywatnej sali.',
 1000.00, 'godz.', TRUE, 180);


-- =====================================================
-- 6. PRACOWNICY ↔ USŁUGI
-- RELACJA N:M
-- =====================================================

INSERT INTO employee_services
(employee_id, service_id)
VALUES
(1, 1),
(1, 2),
(1, 3),

(2, 4),
(2, 5),

(3, 1),
(3, 2),
(3, 3),
(3, 6);


-- =====================================================
-- 7. DOSTĘPNOŚĆ PRACOWNIKÓW
-- 1 = poniedziałek
-- 2 = wtorek
-- 3 = środa
-- 4 = czwartek
-- 5 = piątek
-- 6 = sobota
-- 7 = niedziela
-- =====================================================

INSERT INTO employee_availability
(employee_id, day_of_week, start_time, end_time)
VALUES

-- Szef kuchni
(1, 1, '14:00:00', '22:00:00'),
(1, 2, '14:00:00', '22:00:00'),
(1, 3, '14:00:00', '22:00:00'),
(1, 4, '14:00:00', '22:00:00'),
(1, 5, '14:00:00', '23:00:00'),
(1, 6, '14:00:00', '23:00:00'),

-- Sommelier
(2, 2, '16:00:00', '22:00:00'),
(2, 3, '16:00:00', '22:00:00'),
(2, 4, '16:00:00', '22:00:00'),
(2, 5, '16:00:00', '23:00:00'),
(2, 6, '16:00:00', '23:00:00'),

-- Manager
(3, 1, '12:00:00', '20:00:00'),
(3, 2, '12:00:00', '20:00:00'),
(3, 3, '12:00:00', '20:00:00'),
(3, 4, '12:00:00', '20:00:00'),
(3, 5, '12:00:00', '22:00:00'),
(3, 6, '12:00:00', '22:00:00');


-- =====================================================
-- 8. REZERWACJE
-- =====================================================

INSERT INTO reservations
(reservation_id, user_id, employee_id, service_id,
 reservation_date, start_time, end_time,
 guest_count, status, comment)
VALUES

(1, 1, 1, 1,
 '2026-10-10', '18:00:00', '20:00:00',
 2, 'confirmed',
 'Kolacja rocznicowa.'),

(2, 2, 2, 4,
 '2026-10-10', '19:00:00', '21:00:00',
 4, 'pending',
 'Prośba o dobór win do menu.');