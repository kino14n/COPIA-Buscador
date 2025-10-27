CREATE TABLE IF NOT EXISTS _control_clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(50) UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(120) DEFAULT NULL,
  activo BOOLEAN DEFAULT 1,
  fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso DATETIME NULL
);
CREATE INDEX IF NOT EXISTS idx_control_clientes_codigo ON _control_clientes (codigo);
