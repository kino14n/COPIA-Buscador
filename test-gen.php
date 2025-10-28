<?php
// Script de prueba para verificar la ejecución de PHP en la raíz del proyecto
header('Content-Type: text/html; charset=utf-8');

echo "<h1>✅ El servidor puede ejecutar PHP en esta ubicación</h1>";

echo '<section>';
echo '<h2>Información básica del servidor</h2>';
echo '<ul>';
echo '<li><strong>Fecha:</strong> ' . date('Y-m-d H:i:s') . '</li>';
echo '<li><strong>Archivo ejecutado:</strong> ' . htmlspecialchars(__FILE__, ENT_QUOTES, 'UTF-8') . '</li>';
echo '<li><strong>Versión de PHP:</strong> ' . PHP_VERSION . '</li>';
echo '</ul>';
echo '</section>';

echo '<section>';
echo '<h2>phpinfo()</h2>';
ob_start();
phpinfo();
$phpinfo = ob_get_clean();

// Reducir la cantidad de información mostrada eliminando cabeceras repetidas
echo '<details open>';
echo '<summary>Ver detalles de phpinfo()</summary>';
echo $phpinfo;
echo '</details>';

echo '</section>';
