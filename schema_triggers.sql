-- =====================================================================
-- Joyería Onix · schema_triggers.sql  (Bloque 1.2)  ·  OPCIONAL
-- Defensa en profundidad a nivel de base de datos:
--   1) kardex y bitacora son de solo-agregar (UPDATE / DELETE denegados).
--   2) no se puede insertar una venta sin una caja ABIERTA.
--
-- El instalador los ejecuta uno por uno (PDO::exec, sin depender del cliente).
-- Si el hosting compartido no permite triggers, el instalador solo avisa:
-- la aplicación funciona igual (las mismas reglas ya se validan en PHP).
--
-- TRUNCATE no dispara estos triggers, así que tests/ (que vacía tablas) no se afecta.
--
-- Importación manual (mysql CLI o phpMyAdmin): respeta los DELIMITER.
--   mysql -u root --default-character-set=utf8mb4 BASE < schema_triggers.sql
-- Es re-ejecutable: cada trigger se elimina antes de crearse.
-- =====================================================================

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_kardex_no_update`$$
CREATE TRIGGER `trg_kardex_no_update` BEFORE UPDATE ON `kardex` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operación denegada: el kardex es inmutable (solo se agregan movimientos).';
END$$

DROP TRIGGER IF EXISTS `trg_kardex_no_delete`$$
CREATE TRIGGER `trg_kardex_no_delete` BEFORE DELETE ON `kardex` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operación denegada: el kardex es inmutable (no se puede borrar).';
END$$

DROP TRIGGER IF EXISTS `trg_bitacora_no_update`$$
CREATE TRIGGER `trg_bitacora_no_update` BEFORE UPDATE ON `bitacora` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operación denegada: la bitácora es inmutable (solo se agregan registros).';
END$$

DROP TRIGGER IF EXISTS `trg_bitacora_no_delete`$$
CREATE TRIGGER `trg_bitacora_no_delete` BEFORE DELETE ON `bitacora` FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operación denegada: la bitácora es inmutable (no se puede borrar).';
END$$

DROP TRIGGER IF EXISTS `trg_ventas_caja_abierta`$$
CREATE TRIGGER `trg_ventas_caja_abierta` BEFORE INSERT ON `ventas` FOR EACH ROW
BEGIN
  IF NEW.id_caja IS NULL OR NOT EXISTS (SELECT 1 FROM `cajas` WHERE `id_caja` = NEW.id_caja AND `estado` = 'ABIERTA') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operación denegada: no se puede registrar una venta sin una caja abierta.';
  END IF;
END$$

DELIMITER ;
