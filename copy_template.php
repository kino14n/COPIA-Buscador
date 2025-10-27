<?php
function copyDir($src, $dst) {
  $dir = opendir($src);
  @mkdir($dst, 0777, true);
  while(false !== ($file = readdir($dir))) {
    if (($file != '.') && ($file != '..')) {
      if (is_dir($src . '/' . $file)) {
        copyDir($src . '/' . $file, $dst . '/' . $file);
      } else {
        copy($src . '/' . $file, $dst . '/' . $file);
      }
    }
  }
  closedir($dir);
}
?>
