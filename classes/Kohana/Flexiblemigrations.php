<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Flexible Migrations
 *
 * An open source utility inspired by Ruby on Rails
 *
 * Reworked for Kohana by Fernando Petrelli
 *
 * Based on Migrations module by Jamie Madill
 * Extended by Dr. Manuel Lamotte-Schubert to support
 * migration files spread over application and modules.
 *
 * @package     Flexiblemigrations
 * @author      Matías Montes
 * @author      Jamie Madill
 * @author      Fernando Petrelli
 * @author      Dr. Manuel Lamotte-Schubert
 */
class Kohana_Flexiblemigrations
{
    protected $_config;

    public function __construct()
    {
        $this->_config = Kohana::$config->load('flexiblemigrations')->as_array();

        // Create maintenance table migrations if it doesn't exist
        $db_config = method_exists('Database', 'default_name') ? Database::default_name() : Database::$default;
        $cur_db = Kohana::$config->load('database.'.$db_config.'.connection.database');
        try {
            $table_exists = (bool) DB::query(Database::SELECT, "SELECT * FROM information_schema.tables WHERE table_name='migrations' AND table_schema='".$cur_db."'")->execute()->count();
            if ( ! $table_exists)
            {
                $dump = file_get_contents(Kohana::find_file(NULL, 'migrations', 'sql'));
                DB::query(NULL, $dump)->execute();
            }
        }
        catch (Database_Exception $e) {}
    }

    public function get_config()
    {
        return $this->_config;
    }


    /**
     * Run all pending migrations
     */
    public function migrate()
    {
        $migration_keys = $this->get_migration_keys();
        $migrations = ORM::factory('migration')->find_all();
        $messages = array();

        // Remove executed migrations from queue
        foreach ($migrations as $migration) {
            if (array_key_exists($migration->hash, $migration_keys)) {
                unset($migration_keys[$migration->hash]);
            }
        }

        if (count($migration_keys) > 0) {
            foreach ($migration_keys as $key => $value) {
                $msg = "Executing migration: '".$value."' with hash: ".$key;

                try {
                    $now = date('Y-m-d', time());
                    $migration_object = $this->load_migration($key, $value);
                    $migration_object->up();
                    $model = ORM::factory('migration');
                    $model->hash = $key;
                    $model->name = $value;
                    $model->created_at = $now;
                    $model->updated_at = $now;
                    $model->save();
                    $model ? $messages[] = array(0 => $msg) : $messages[] = array(1 => $msg);
                } catch (Exception $e) {
                    $messages[] = array(1 => $msg."\n".$e->getMessage());

                    return $messages;
                }
            }
        }

        return $messages;
    }


    /**
     * Rollback last executed migration.
     *
     */
    public function rollback()
    {
        //Get last executed migration
        $model = ORM::factory('migration')
            ->order_by('created_at', 'DESC')
            ->order_by('hash', 'DESC')
            ->limit(1)
            ->find();
        $messages = array();

        if ($model->loaded()) {
            try {
                $migration_object = $this->load_migration($model->hash, $model->name);
                $migration_object->down();

                if ($model) {
                    $msg = "Migration '".$model->name."' with hash: ".$model->hash.' was succesfully "rolled back"';
                    $messages[] = array(0 => $msg);
                } else {
                    $messages[] = array(1 => "Error executing rollback");
                }
                $model->delete();
            } catch (Exception $e) {
                $messages[] = array(1 => $e->getMessage());
            }
        } else {
            $messages[] = array(1 => "There's no migration to rollback");
        }

        return $messages;
    }


    /**
     * Rollback last executed migration.
     *
     */
    public function get_timestamp()
    {
        return date('YmdHis');
    }


    /**
     * Get all valid migrations file names
     *
     * @return array migrations_filenames
     */
    public function get_migrations()
    {
        // Include paths must be searched in reverse
        $paths = array_reverse(Kohana::include_paths());

        // Array of files that have been found
        $migrations = array();

        // Search pattern
        $path = $this->_config['path'].DIRECTORY_SEPARATOR.'*'.EXT;

        foreach ($paths as $dir) {
            $files = glob($dir.$path);
            foreach ($files as $file) {
                // We have a file and filename format matches -> Add to migrations array
                if (is_file($file) and preg_match('/^\d{14}_(\w+)$/', basename($file, EXT))) {
                    // This path has a file, add it to the list
                    $migrations[] = $file;
                }
            }
        }

        usort($migrations, function ($a, $b) {
            return strcasecmp(basename($a), basename($b));
        });

        return $migrations;
    }


    /**
     * Generates a new migration file
     * TODO: Probably needs to be in outer class
     *
     * @return integer completion_code
     */
    public function generate_migration($migration_name)
    {
        try {
            //Creates the mig   ration file with the timestamp and the name from params
            $file_name = $this->get_timestamp().'_'.$migration_name.'.php';
            $config = $this->get_config();
            $file = fopen(APPPATH.$config['path'].DIRECTORY_SEPARATOR.$file_name, 'w+');

            //Opens the template file and replaces the name
            $view = new View('migration_template');
            $view->set_global('migration_name', $migration_name);
            fwrite($file, $view);
            fclose($file);
            chmod(APPPATH.$config['path'].DIRECTORY_SEPARATOR.$file_name, 0770);

            return 0;
        } catch (Exception $e) {
            return 1;
        }
    }


    /**
     * Get all migration keys (timestamps)
     *
     * @return array migrations_keys
     */
    protected function get_migration_keys()
    {
        $migrations = $this->get_migrations();
        $keys = array();
        foreach ($migrations as $migration) {
            $sub_migration = substr(basename($migration, EXT), 0, 14);
            $keys = Arr::merge($keys, array($sub_migration => substr(basename($migration, EXT), 15)));
        }

        return $keys;
    }


    /**
     * Load the migration file, and returns a Migration object
     *
     * @return Migration object with up and down functions
     */
    protected function load_migration($version, $name)
    {
        $f = Kohana::find_file($this->_config['path'], $version.'_'.$name, null, true);

        if (count($f) > 1) // Only one migration per step is permitted
        {
            throw new Kohana_Exception('There are repeated migration names');
        }

        if (count($f) == 0) // Migration step not found
        {
            throw new Kohana_Exception("There's no migration to rollback");
        }

        $file = basename($f[0]);
        $name = basename($f[0], EXT);

        // Filename validation
        if ( ! preg_match('/^\d{14}_(\w+)$/', $name, $match)) {
            throw new Kohana_Exception('Invalid filename :file', array(':file' => $file));
        }

        $match[1] = strtolower($match[1]);
        require $f[0]; //Includes migration class file

        $class = ucfirst($match[1]); //Get the class name capitalized

        if ( ! class_exists($class)) {
            throw new Kohana_Exception('Class :class doesn\'t exists', array(':class' => $class));
        }

        if ( ! method_exists($class, 'up') or ! method_exists($class, 'down')) {
            throw new Kohana_Exception('Up or down functions missing on class :class', array(':class' => $class));
        }

        return new $class();
    }

}
