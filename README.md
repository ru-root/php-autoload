## Велосипедный Autoload classes

[![https://img.shields.io/badge/PHP-8.0-blue](https://img.shields.io/badge/PHP-8.0-blue)](https://www.php.net/releases/8.0/en.php)


    - PSR-0 autoloading
    - PSR-4 autoloading
____

- Один из механизмов для создания карты пространств имен и классов приложений,
придерживаясь рекомендованых стандартов (PHP Standards Recommendations) PSR-4 или PSR-0
- Он сканирует указанные каталоги в поисках **\*.php** файлов сохраняя список всех найденных классов в кэш,
для повторного использования, с минимальными затратами времени на интерпретацию автозагрузчика 


# Example
```
  <?php
      (require __DIR__ .DIRECTORY_SEPARATOR .'vendor' .DIRECTORY_SEPARATOR .'autoload.php')
          // ->setApcu('APP_NAME')
          ->setCacheKey('CacheKeyName')
          ->logger(false) // FALSE Display error, (TRUE or empty) Log error
          ->setPaths([
              __DIR__ .DIRECTORY_SEPARATOR .'classes',
              __DIR__ .DIRECTORY_SEPARATOR .'vendor',
              APP_PATH .'classes',
              APP_PATH .'vendor',
          ])->includes([
              'functions',
              'FastRoute\functions',
              'bootstrap'
          ]);
  
    Autoloader::include('settings', 'config');
```
