<?php
/*
 * OpenSTAManager: il software gestionale open source per l'assistenza tecnica e la fatturazione
 * Copyright (C) DevCode s.r.l.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// Impostazioni di configurazione PHP
date_default_timezone_set('Europe/Rome');

// Controllo sulla versione PHP
$minimum = '5.6.0';
if (version_compare(phpversion(), $minimum) < 0) {
    echo '
<p>Stai utilizzando la versione PHP '.phpversion().', non compatibile con OpenSTAManager.</p>

<p>Aggiorna PHP alla versione >= '.$minimum.'.</p>';
    throw new \App\Exceptions\LegacyExitException();
}

// Caricamento delle impostazioni personalizzabili
if (file_exists(__DIR__.'/config.inc.php')) {
    include_once __DIR__.'/config.inc.php';
}

/*
// Sicurezza della sessioni
ini_set('session.cookie_samesite', 'strict');
ini_set('session.use_trans_sid', '0');
ini_set('session.use_only_cookies', '1');

session_set_cookie_params(0, base_url(), null, isHTTPS(true));
session_start();*/

/* GESTIONE DEGLI ERRORI */
// Logger per la segnalazione degli errori
$logger = new Monolog\Logger('Logs');
$logger->pushProcessor(new Monolog\Processor\UidProcessor());
$logger->pushProcessor(new Monolog\Processor\WebProcessor());

// Registrazione globale del logger
Monolog\Registry::addLogger($logger, 'logs');

use Monolog\Handler\FilterHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;

$handlers = [];
if (!$api_request) {
    // File di log di base (logs/error.log, logs/setup.log)
    $handlers[] = new StreamHandler(base_dir().'/logs/error.log', Monolog\Logger::ERROR);
    $handlers[] = new StreamHandler(base_dir().'/logs/setup.log', Monolog\Logger::EMERGENCY);

    // Messaggi grafici per l'utente
    $handlers[] = new Extensions\MessageHandler(Monolog\Logger::ERROR);

    // File di log ordinati in base alla data
    if (AppLegacy::debug()) {
        $handlers[] = new RotatingFileHandler(base_dir().'/logs/error.log', 0, Monolog\Logger::ERROR);
        $handlers[] = new RotatingFileHandler(base_dir().'/logs/setup.log', 0, Monolog\Logger::EMERGENCY);
    }

    // Inizializzazione Whoops
    $whoops = new Whoops\Run();

    if (AppLegacy::debug()) {
        $whoops->pushHandler(new Whoops\Handler\PrettyPageHandler());
    }

    // Abilita la gestione degli errori nel caso la richiesta sia di tipo AJAX
    if (Whoops\Util\Misc::isAjaxRequest()) {
        $whoops->pushHandler(new Whoops\Handler\JsonResponseHandler());
    }

    $whoops->register();

    // Aggiunta di Monolog a Whoops
    $whoops->pushHandler(function ($exception, $inspector, $run) use ($logger) {
        $logger->addError($exception->getMessage(), [
            'code' => $exception->getCode(),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    });
} else {
    $handlers[] = new StreamHandler(base_dir().'/logs/api.log', Monolog\Logger::ERROR);
}

// Disabilita i messaggi nativi di PHP
ini_set('display_errors', 0);
// Ignora gli avvertimenti e le informazioni relative alla deprecazione di componenti
error_reporting(E_ALL & ~E_WARNING & ~E_CORE_WARNING & ~E_NOTICE & ~E_USER_DEPRECATED & ~E_STRICT);

$pattern = '[%datetime%] %channel%.%level_name%: %message% %context%'.PHP_EOL.'%extra% '.PHP_EOL;
$monologFormatter = new Monolog\Formatter\LineFormatter($pattern);
$monologFormatter->includeStacktraces(AppLegacy::debug());

// Filtra gli errori per livello preciso del gestore dedicato
foreach ($handlers as $handler) {
    $handler->setFormatter($monologFormatter);
    $logger->pushHandler(new FilterHandler($handler, [$handler->getLevel()]));
}

// Imposta Monolog come gestore degli errori
$handler = new Monolog\ErrorHandler($logger);
if (!API\Response::isAPIRequest()) {
    $handler->registerErrorHandler([]);
    $handler->registerExceptionHandler(Monolog\Logger::ERROR);
}
$handler->registerFatalHandler(Monolog\Logger::ERROR);

// Database
$dbo = $database = database();

/* INTERNAZIONALIZZAZIONE */

// Individuazione di versione e revisione del progetto
$version = Update::getVersion();
$revision = Update::getRevision();

/* ACCESSO E INSTALLAZIONE */
// Controllo sulla presenza dei permessi di accesso basilari
$continue = $dbo->isInstalled() && !Update::isUpdateAvailable() && (auth()->check() || $api_request);

if (!empty($skip_permissions)) {
    Permissions::skip();
}

if (!$continue && getURLPath() != slashes(base_url().'/index.php') && !Permissions::getSkip()) {
    if (auth()->check()) {
        auth()->logout();
    }

    redirect_legacy(base_url().'/');
    throw new \App\Exceptions\LegacyExitException();
}

/* INIZIALIZZAZIONE GENERALE */
// Operazione aggiuntive (richieste non API)
if (!$api_request) {
    // Impostazioni di Content-Type e Charset Header
    header('Content-Type: text/html; charset=UTF-8');

    // Registrazione globale del template per gli input HTML
    ob_start();

    // Retrocompatibilità
    session(['infos' => isset($_SESSION['infos']) ? array_unique($_SESSION['infos']) : []]);
    session(['warnings' => isset($_SESSION['warnings']) ? array_unique($_SESSION['warnings']) : []]);
    session(['errors' => isset($_SESSION['errors']) ? array_unique($_SESSION['errors']) : []]);

    // Impostazione del tema grafico di default
    $theme = 'default';

    if ($continue) {
        // Periodo di visualizzazione dei record
        // Personalizzato
        if (!empty($_GET['period_start'])) {
            session(['period_start' => $_GET['period_start']]);
            session(['period_end' => $_GET['period_end']]);
        }
        // Dal 01-01-yyy al 31-12-yyyy
        elseif (session('period_start') == null) {
            session(['period_start' => date('Y').'-01-01']);
            session(['period_end' => date('Y').'-12-31']);
        }

        $id_record = filter('id_record');
        $id_parent = filter('id_parent');

        Modules::setCurrent(filter('id_module'));
        Plugins::setCurrent(filter('id_plugin'));

        // Variabili fondamentali
        $module = Modules::getCurrent();
        $plugin = Plugins::getCurrent();
        $structure = isset($plugin) ? $plugin : $module;

        $id_module = $module ? $module['id'] : null;
        $id_plugin = $plugin ? $plugin['id'] : null;

        $user = auth()->user();

        if (!empty($id_module)) {
            // Segmenti
            if (session('module_'.$id_module.'.id_segment') === null) {
                $segments = Modules::getSegments($id_module);
                session(['module_'.$id_module.'.id_segment' => isset($segments[0]['id']) ? $segments[0]['id'] : null]);
            }

            Permissions::addModule($id_module);
        }

        Permissions::check();
    }

    // Retrocompatibilità
    $post = Filter::getPOST();
    $get = Filter::getGET();
}

// Inclusione dei file modutil.php
// TODO: sostituire * con lista module dir {aggiornamenti,anagrafiche,articoli}
// TODO: sostituire tutte le funzioni dei moduli con classi Eloquent relative
$files = glob(__DIR__.'/{modules,plugins}/*/modutil.php', GLOB_BRACE);
$custom_files = glob(__DIR__.'/{modules,plugins}/*/custom/modutil.php', GLOB_BRACE);
foreach ($custom_files as $key => $value) {
    $index = array_search(str_replace('custom/', '', $value), $files);
    if ($index !== false) {
        unset($files[$index]);
    }
}

$list = array_merge($files, $custom_files);
foreach ($list as $file) {
    include_once $file;
}

// Inclusione dei file vendor/autoload.php di Composer
$files = glob(__DIR__.'/{modules,plugins}/*/vendor/autoload.php', GLOB_BRACE);
$custom_files = glob(__DIR__.'/{modules,plugins}/*/custom/vendor/autoload.php', GLOB_BRACE);
foreach ($custom_files as $key => $value) {
    $index = array_search(str_replace('custom/', '', $value), $files);
    if ($index !== false) {
        unset($files[$index]);
    }
}

$list = array_merge($files, $custom_files);
foreach ($list as $file) {
    include_once $file;
}
