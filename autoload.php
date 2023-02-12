<?php /*﻿*/ declare(strict_types=1);
/*
 * @package ArrayMap
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
 *
 * @example:
 * <?php
 *     Autoloader::logger(FALSE); // Enable display error
 *     Autoloader::setPath(['/path/dir', '/path/appName']);
 *     Autoloader::autoloadFunctions(); // @see Autoloader::register
 *     Autoloader::includeFile('/path/appName/bootstrap.php');
 *  // Autoloader::register();
 *  // VS
 *     spl_autoload_register('Autoloader::autoload', TRUE, FALSE);
 */
abstract class Autoloader {

    public const EXT = '.php';

    private const CACHE_TTL = 86400; // 24h

    private static array   $classMap    = [];
    private static array   $searchPaths = [];
    private static bool     $writeFile   = FALSE;
    private static ?Closure $includeFile = NULL;
    private static ?string  $apcuPrefix  = NULL;
    private static string   $fileCache   = __DIR__ .DIRECTORY_SEPARATOR .__CLASS__ .'_cache' .self::EXT;


    /** Loads the given class or interface. **/
    public static function loadClass(string $class): bool
    {
        /**
         * // PSR-4 lookup
         * $class = strtr($class, '\\', DIRECTORY_SEPARATOR);
         *
         * // PSR-0 lookup - !!! DEPRECATED !!!
         * if ($pos = strrpos($class, '\\'))
         *     $class = substr($class, 0, $pos = ($pos + 1))
         *              .strtr(substr($class, $pos), '_', DIRECTORY_SEPARATOR);
         * else {
         *
         *    // PEAR-like class name
         *    $class = strtr($class, '_', DIRECTORY_SEPARATOR);
         * }
         */

        // PSR-0 PSR-4 and PEAR-like class name lookup
        $class = str_replace(['\\', '_'], DIRECTORY_SEPARATOR, ltrim($class, '\\'));

        return ($file = self::findFile('', $class))
            ? (bool) (self::$include)($file)
            : FALSE;
    }


    /** Finds the path to the file where the class is defined. **/
    public static function findFile(string $dir, string $class, string $ext = self::EXT): string|FALSE
    {
        $class = ltrim($dir .DIRECTORY_SEPARATOR, '/\\') .$class .$ext;
        if (isset(self::$classMap[$class]))
            return self::$classMap[$class];
        elseif ( ! is_null(self::$apcuPrefix)) {
            $file = apcu_fetch(self::$apcuPrefix .$class, $bool);
            if ($bool) return $file;
        }

        $file = FALSE;
        foreach (self::$searchPaths as & $_) {
            if (is_file($_ .$class)) {
                $file = self::$classMap[$class] = $_ .$class;
                // Remember that this class.
                self::$writeFile = is_null(self::$apcuPrefix)
                    || apcu_add(self::$apcuPrefix .$class, $file, self::CACHE_TTL);
                break;
            }
        }

        if ( ! $file) {
            // Unset that this class does not exist.
            is_null(self::$apcuPrefix) || apcu_delete(self::$apcuPrefix .$class);
            unset(self::$classMap[$class]);
        }

        return $file;
    }


    public static function cache(array $data = NULL): array
    {
        if (is_null($data)) {
           if (is_file(self::$fileCache)) {
               if ((time() - filemtime(self::$fileCache)) < self::CACHE_TTL) {
                   return (self::$includeFile)(self::$fileCache);
               }
               self::clearCache();
           }
           $data = [];
        } else {
           // This a local static variable to avoid instantiating a closure each time we need an empty handler
           ! is_writable(__DIR__)
               || set_error_handler(static function (): void {})
               | file_put_contents(self::$fileCache, /* "\xEF\xBB\xBF" */
                   '<?php /*﻿*/// Generated by ' .__CLASS__ ."::class\nreturn " .var_export($data, TRUE) .';',
                   LOCK_EX) | restore_error_handler();
        }
        return $data;
    }


    public static function clearCache(): void
    {
        set_error_handler(static function (): void {})
            | unlink(self::$fileCache)
            | restore_error_handler()
            | is_null(self::$apcuPrefix) || apcu_clear_cache();
    }


    public static function logger(bool|string $log = TRUE): void
    {
        static $static;

        if ( ! isset($static) && $log || is_string($log)) {
            ini_set('log_errors',   $static = $log ? '1' : '0');
            ini_set('display_errors',         $log ? '0' : '1');
            ini_set('display_startup_errors', $log ? '0' : '1');
            ! $log || ini_set('error_log', (is_string($log) ? $log : dirname(__DIR__) .DIRECTORY_SEPARATOR) .'php_error.log');
        }
    }


    /**
     * Registers this instance as an autoloader.
     * @param bool $prepend Whether to prepend the autoloader or not
     */
    public static function register(bool $prepend = FALSE): void
    {
        if (is_null(self::$includeFile)) {

            // Silence E_WARNING to ignore "include" failures - don't use "@" to prevent silencing fatal errors
            error_reporting(E_ALL /* E_ALL ^ E_WARNING */);

            self::$searchPaths = [dirname(__DIR__) .DIRECTORY_SEPARATOR];

            /** Scope isolated include. Prevents access to $this/self from included files. **/
            self::$includeFile = static function(string $file): mixed {
                return include $file;
            };

            spl_autoload_register([__CLASS__, 'loadClass'], TRUE, $prepend);

            register_shutdown_function(static function(): void {
                // Remember in file vs apcu.
                self::$apcuPrefix || FALSE === self::$writeFile || self::cache(self::$classMap);
                if ($_ = error_get_last()) {
                    // On error write log and remove cache and buffer
                    error_log($_['type'] . ': ' .$_['message'] .' file ' .$_['file'] .' line ' .$_['line'])
                        | self::clearCache()
                        | (ob_get_level() && ob_clean())
                        | exit('Error type:' .$_['type'] .' - See log!');
                }
            });

            self::$apcuPrefix || self::$classMap = self::cache();
        }
    }


    /** Unregisters this instance as an autoloader. **/
    public static function unregister(): void
    {
        if ( ! is_null(self::$includeFile)) {
            spl_autoload_unregister([__CLASS__, 'loadClass']);
            self::$includeFile = self::$apcuPrefix = NULL;
            self::$classMap = [];
        }
    }


    /** 
     * APCu prefix to use to cache found/not-found classes, if the extension is enabled.
     * @see self::logger
     */
    final public static function setApcuPrefix(string $apcuPrefix): void
    {
        self::logger();
        if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) 
           self::$apcuPrefix = $apcuPrefix;
    }


    /** The APCu prefix in use, or null if APCu caching is not enabled. **/
    final public static function getApcuPrefix(): ?string
    {
        return self::$apcuPrefix;
    }


    /** @see self::register **/
    final public static function setPath(array $path): void
    {
        self::register();
        foreach ($path as & $path) /** FIFO **/
              in_array($path = rtrim($path, '/\\') .DIRECTORY_SEPARATOR, self::$searchPaths, TRUE)
                  || array_unshift(self::$searchPaths, $path);
    }


    final public static function getPath(): array
    {
        return self::$searchPaths;
    }


    final public static function includeFile(string|array $file, string $dir = ''): void
    {
        $file = (array) $file;
        foreach ($file as & $file)
            ! is_file($file) && ! ($file = self::findFile($dir, $file)) || (self::$includeFile)($file);
    }


    final public static function autoloadFunctions(string $dir = 'functions', string $prefix = '_'): void
    {
        foreach (self::$searchPaths as & $path) {
            if ($_ = glob($path .$dir .DIRECTORY_SEPARATOR .$prefix .'*' .self::EXT, GLOB_NOSORT | GLOB_ERR))
               foreach ($_ as & $_) (self::$includeFile)($_);
        }
    }
}

Autoloader::logger();
// EOF
