<?php /*﻿*/ declare(strict_types=1);
 /**
  * @license https://opensource.org/licenses/MIT  MIT License
  * The MIT License (MIT)
  *
  * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  * SOFTWARE.
  *
  * Simple Recursive Autoloader
  * A simple autoloader that loads class files recursively starting in the directory
  * where this class resides.  Additional options can be provided to control the naming
  * convention of the class files.
  */
 /**
  * namespace Ruroot;
  *
  * use function is_null;         // used 6
  * use function is_file;         // used 4
  * use function ini_set;         // used 5
  * use function sprintf;         // used 3
  * use function is_writable;     // used 2
  * use function rtrim;           // used 2
  * use function ltrim;           // used 2
  * use function strtr;           // used 2
  * use function in_array;        // used 2
  * use function error_reporting; // used 2
  *
  * use const DIRECTORY_SEPARATOR; // used 9
  * use const __DIR__;             // used 3
  */
 /**
  * @VERSION '2.0'
  *
  * @example:
  * <?php
  *    (require __DIR__ .DIRECTORY_SEPARATOR .'vendor' .DIRECTORY_SEPARATOR .'autoload.php')
  *        // ->setApcu('App_Name')
  *        ->setCacheKey('Cache_Key_Name')
  *        ->logger(FALSE) // FALSE Display error, (TRUE or empty) Log error
  *        ->setPaths([
  *            __DIR__ .DIRECTORY_SEPARATOR .'classes',
  *            __DIR__ .DIRECTORY_SEPARATOR .'vendor',
  *            APP_PATH .'classes',
  *            APP_PATH .'vendor',
  *        ])->includes([
  *            'functions',
  *            'bootstrap'
  *        ]);
  *
  *     Autoloader::include('settings', 'path/config', '.inc');
  */
class Autoloader
{
    final public const TTL = 86400; // 24h
    final public const EXT = '.php';
    final protected const ERROR_FILE    = __DIR__ .DIRECTORY_SEPARATOR .'..' .DIRECTORY_SEPARATOR .'php_autoload.log';
    final protected const ERROR_STR_LOG = 'PHP %s: %s in %s on line %s';
    final protected const ERROR_STR_DSP = 'PHP %s: %s - See logs!';
    final protected const ERROR_TYPE    = [
        1    => 'Error',
        256  => 'Error',
        4    => 'Parse',
        2    => 'Warning',
        8    => 'Notice',
        2048 => 'Strict',
        8192 => 'Deprecated',
        4096 => 'Recoverable',
    ];

    private static self $anonymous;

    /**
     * Registers this instance as an autoloader.
     * Silence E_ALL ^ E_WARNING to ignore "include" failures - don't use "@" to prevent silencing fatal errors!
     */
    final public static function register(): self
    {
        return self::$anonymous ??= new class extends Autoloader
        {
            private static array $paths = [];
            private static array $files = [];
            private static string $cacheFile = __DIR__ .DIRECTORY_SEPARATOR .'autoload_cache' .self::EXT;
            private static string $cacheFileStr = '<?php /*﻿ Generated by Autoloader::class */ return %s;';
            private static bool $found = FALSE;
            private static ?string $apcu = NULL;
            protected static Closure $include;

            final protected function __construct() // No type
            {
                /** Scope isolated include. Prevents access to $this/self from included files. **/
                self::$include = static function(string $_, array $datas = []): mixed {
                    // Import variables to local namespace
                    ! $datas || \extract($datas, \EXTR_OVERWRITE);
                    unset($datas);
                    return include $_;
                };

                /** Loads the given class or interface. **/
                // spl_autoload_extensions(Autoloader::EXT);
                \spl_autoload_register(static function (string $class): bool {
                    // PSR-0 and PEAR-like class name lookup
                    return ($class = self::findFiles('', \str_replace(['\\', '_'], DIRECTORY_SEPARATOR, ltrim($class, '\\'))))
                        ? (bool) include $class
                        : FALSE;
                });

                \register_shutdown_function(static function(): void {
                    // Remember
                    ! is_null(self::$apcu) || FALSE === self::$found || self::cacheFile(self::$files);
                    // Error
                    if ($message = static::registerShutdownErrorLast()) {
                        self::clearCache();
                        exit($message);
                    }
                });
            }

            /** Finds the path to the file where the class is defined. **/
            final protected static function findFiles(string $dir, string $file, string $ext = self::EXT, bool $array = FALSE): string|array|NULL
            {
                $file = ltrim($dir .DIRECTORY_SEPARATOR, '/\\') .$file .$ext;
                $array = (TRUE === $array ? '_array' : '_path');

                if (isset(self::$files["{$file}{$array}"]))
                    return self::$files["{$file}{$array}"];
                elseif (self::$apcu && ($result = \apcu_fetch(self::$apcu ."{$file}{$array}", $itemFetched)) && $itemFetched) {
                    return $result;
                }

                $found = [];
                $_ = ('_array' === $array) ? \array_reverse(self::$paths) : self::$paths;
                foreach ($_ as $_) {
                    if (is_file($_ .$file)) {
                        if ('_array' === $array)
                            $found[] = $_ .$file;
                        else {
                            $found = $_ .$file;
                            break;
                        }
                    }
                }

                if ($found) {
                    if ( ! (self::$found = is_null(self::$apcu))) {
                        \apcu_add(self::$apcu ."{$file}{$array}", $found, self::TTL);
                    } else {
                        self::$files["{$file}{$array}"] = $found;
                        self::$found = TRUE;
                    }
                    return $found;
                }

                return '_array' === $array ? [] : NULL;
            }

            final public static function cacheFile(array $data = NULL): array
            {
                if (is_null($data)) {
                    if (is_file(self::$cacheFile)) {
                        if ((\time() - \filemtime(self::$cacheFile)) < self::TTL) {
                            return include self::$cacheFile;
                        }
                        self::clearCache();
                    }
                    $data = [];
                } else {
                    \file_put_contents(self::$cacheFile, sprintf(self::$cacheFileStr, \var_export($data, TRUE)), \LOCK_EX);
                }
                return $data;
            }

            final public static function clearCache(): void
            {
                \unlink(self::$cacheFile);
                is_null(self::$apcu) || \apcu_clear_cache();
            }

            final public function logger(bool|int|string $log = TRUE): static
            {
                static $static;
                if ( ! isset($static) || $static !== $log) {
                    error_reporting((\is_int($log) ? $log : E_ALL));
                    ini_set('html_errors',                         '0');
                    ini_set('log_errors',             $log ? '1' : '0');
                    ini_set('display_errors',         $log ? '0' : '1');
                    ini_set('display_startup_errors', $log ? '0' : '1');
                    ! ($static = $log)
                        || ini_set('error_log', (\is_string($log) ? $log : self::ERROR_FILE));
                }
                return $this;
            }

            /** APCu prefix to use to cache found/not-found classes, if the extension is enabled. **/
            final public function setApcu(string $apcu): self
            {
                // @link https://www.php.net/manual/ru/apcu.configuration.php#ini.apcu.enabled
                if (\function_exists('apcu_enabled') && \apcu_enabled()) {
                    self::$apcu = $apcu;
                }
                return $this;
            }

            final public function setCacheKey(?string $cacheKey = NULL, string $path = ''): static
            {
                if (is_null(self::$apcu)) {
                    self::$cacheFile = (is_writable($path)
                        ? rtrim($path, '\\/')
                        : __DIR__) .DIRECTORY_SEPARATOR .'_' .$cacheKey .'_' .\basename(self::$cacheFile);

                    self::$files = (array) self::cacheFile();
                }
                return $this;
            }

            final public function setPaths(array $paths): static
            {
                foreach ($paths as $paths) /** FIFO **/ {
                    in_array($paths = rtrim($paths, '/\\') .DIRECTORY_SEPARATOR, self::$paths, TRUE)
                       || \array_unshift(self::$paths, $paths);
                }
                return $this;
            }

            final public function includes(array $files, string $dir = ''): static
            {
                foreach ($files as $file) {
                    ! is_file($file) && ! ($file = self::findFiles(strtr($dir, '\\', DIRECTORY_SEPARATOR), $file))
                        || (self::$include)($file);
                }
                return $this;
            }

            /** Unregisters this instance. **/
            final public function unregister(): static
            {
                if (self::$include) {
                    \spl_autoload_unregister(\spl_autoload_functions()[0]);
                    self::clearCache();
                    self::$include = self::$apcu = NULL;
                    self::$files = [];
                }
                return $this;
            }
        };
    }

    final public static function findFile(string $dir, string $file, string $ext = self::EXT, bool $array = FALSE): string|array|NULL
    {
        return self::$anonymous::findFiles(strtr($dir, '\\', DIRECTORY_SEPARATOR), $file, $ext, $array);
    }

    final public static function include(string $file, string $dir = '', array $data = [], string $ext = self::EXT): mixed
    {
        return is_file($file) || ($file = self::$anonymous::findFiles($dir, $file, $ext))
            ? (self::$anonymous::$include)($file, $data)
            : NULL;
    }

    final protected static function registerShutdownErrorLast(): string|int
    {
        $message = 0;
        if ($err = \error_get_last()) {
            $err['type'] = self::ERROR_TYPE[$err['type']] ?? 'Unknown';
            if (0 === ($message = (int) \filter_var(ini_get('display_errors'), \FILTER_VALIDATE_BOOLEAN))) {
                $message = sprintf(self::ERROR_STR_DSP, $err['type'], $err['message']);
            }

            if (0 === $message || E_ALL !== error_reporting()) {
                \error_log(
                    sprintf(self::ERROR_STR_LOG, $err['type'], $err['message'], $err['file'], $err['line'])
                );
            }

            if (in_array($err['type'], [E_PARSE, E_ERROR, E_USER_ERROR])) {
                $message = 1;
                \ob_get_level() && \ob_clean();
            }
        }

        return $message;
    }
}

final class Autoload extends Autoloader { /* @see Autoloader */ }
return Autoload::register();
// EOF
