CREATE DATABASE IF NOT EXISTS fotografo_db;
USE fotografo_db;

-- 1. Tabla de eventos
CREATE TABLE IF NOT EXISTS eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    lugar VARCHAR(150) NOT NULL,
    fecha_evento DATE NOT NULL,
    precio_foto DECIMAL(10, 2) NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabla de fotos asociadas al evento
CREATE TABLE IF NOT EXISTS fotos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evento_id INT NOT NULL,
    ruta VARCHAR(255) NOT NULL,
    FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE
);

-- 3. Tabla de ventas
CREATE TABLE IF NOT EXISTS ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    whatsapp VARCHAR(50) NOT NULL,
    email VARCHAR(150) NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    estado VARCHAR(20) DEFAULT 'pendiente',
    mp_preference_id VARCHAR(255) NULL,
    mp_payment_id VARCHAR(255) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Fotos de cada venta (una venta puede tener fotos de distintos eventos)
CREATE TABLE IF NOT EXISTS venta_fotos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    foto_id INT NOT NULL,
    precio DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
    FOREIGN KEY (foto_id) REFERENCES fotos(id)
);