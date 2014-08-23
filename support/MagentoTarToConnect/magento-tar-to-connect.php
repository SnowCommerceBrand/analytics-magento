<?php
// Copyright (c) 2012 - 1013 Pulse Storm LLC.
//
// Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

//in progress, use at your own risk
if (!defined('DS')) define('DS','/');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Los_Angeles');

require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Helper/File.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Interface.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Abstract.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Tar.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Exception.php';

//from http://php.net/glob
if ( ! function_exists('glob_recursive'))
{
    // Does not support flag GLOB_BRACE
    function glob_recursive($pattern, $flags = 0)
    {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir)
        {
            $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
        }
        return $files;
    }
}

function input($string)
{
    echo $string . "\n] ";
    $handle = fopen ("php://stdin","r");
    $line = fgets($handle);
    fclose($handle);
    return $line;
}

function error($string)
{
    echo "ERROR: " . $string . "\n";
    exit;
}


function create_package_xml_add_node($xml, $full_dir, $base_dir=false)
{
    $parts = explode("/",str_replace($base_dir.'/','',$full_dir));
    $single_file  = array_pop($parts);
    $node = $xml;
    foreach($parts as $part)
    {
        $nodes = $node->xpath("dir[@name='".$part."']");
        if(count($nodes) > 0)
        {
            $node = array_pop($nodes);
        }
        else
        {
            $node = $node->addChild('dir');
            $node->addAttribute('name', $part);
        }
    }

    $node = $node->addChild('file');
    $node->addAttribute('name',$single_file);
    $node->addAttribute('hash',md5_file($full_dir));
}

function create_package_xml($files, $base_dir, $config)
{
    $xml = simplexml_load_string('<package/>');
    $xml->name          = $config['extension_name'];
    $xml->version       = $config['extension_version'];
    $xml->stability     = $config['stability'];
    $xml->license       = $config['license'];
    $xml->channel       = $config['channel'];
    $xml->extends       = '';
    $xml->summary       = $config['summary'];
    $xml->description   = $config['description'];
    $xml->notes         = $config['notes'];

    $authors            = $xml->addChild('authors');
    $author             = $authors->addChild('author');
    $author->name       = $config['author_name'];
    $author->user       = $config['author_user'];
    $author->email      = $config['author_email'];

    $xml->date          = date('Y-m-d');
    $xml->time          = date('G:i:s');
    $xml->compatible    = '';
    $dependencies       = $xml->addChild('dependencies');
    $required           = $dependencies->addChild('required');
    $php                = $required->addChild('php');
    $php->min           = $config['php_min'];   //'5.2.0';
    $php->max           = $config['php_max'];   //'6.0.0';

    // add php extension dependencies
    if (is_array($config['extensions'])) {
        foreach ($config['extensions'] as $extinfo) {
            $extension = $required->addChild('extension');
            if (is_array($extinfo)) {
                $extension->name = $extinfo['name'];
                $extension->min = isset($extinfo['min']) ? $extinfo['min'] : "";
                $extension->max = isset($extinfo['max']) ? $extinfo['max'] : "";
            } else {
                $extension->name = $extinfo;
                $extension->min = "";
                $extension->max = "";
            }
        }
    }

    $node = $xml->addChild('contents');
    $node = $node->addChild('target');
    $node->addAttribute('name', 'mage');

    //     $files = $this->recursiveGlob($temp_dir);
    //     $files = array_unique($files);
    $temp_dir = false;
    foreach($files as $file)
    {
        //$this->addFileNode($node,$temp_dir,$file);
        create_package_xml_add_node($node, $file, $base_dir);
    }
    //file_put_contents($temp_dir . '/package.xml', $xml->asXml());

    return $xml->asXml();
}

function get_temp_dir()
{
    $name = tempnam(sys_get_temp_dir(),'tmp');
    unlink($name);
    $name = $name;
    mkdir($name,0777,true);
    return $name;
}

function validate_config($config)
{
    $keys = array('base_dir','archive_files','path_output',
    );
    foreach($keys as $key)
    {
        if(!array_key_exists($key, $config))
        {
            error("Config file missing key [$key]");
        }
    }

    if($config['author_email'] == 'foo@example.com')
    {
        $email = input("Email Address is configured with foo@example.com.  Enter a new address");
        if(trim($email) != '')
        {
            $config['author_email'] = trim($email);
        }
    }

    if(!array_key_exists('extensions', $config))
    {
        $config['extensions'] = null;
    }
    return $config;


}

function load_config($config_name=false)
{
    if(!$config_name)
    {
        $config_name = basename(__FILE__,'php') . 'config.php';
    }
    if(!file_exists($config_name))
    {
        error("Could not find $config_name.  Create this file, or pass in an alternate");
    }
    $config = include $config_name;
    $config = validate_config($config);
    return $config;
}

function get_module_version($files)
{
    $configs = array();
    foreach($files as $file)
    {
        if(basename($file) == 'config.xml')
        {
            $configs[] = $file;
        }
    }

    foreach($configs as $file)
    {
        $xml = simplexml_load_file($file);
        $version_strings = $xml->xpath('//version');
        foreach($version_strings as $version)
        {
            $version = (string) $version;
            if(!empty($version))
            {
                return (string)$version;
            }
        }
    }

    foreach($configs as $file)
    {
        $xml = simplexml_load_file($file);
        $modules = $xml->xpath('//modules');
        foreach($modules[0] as $module)
        {
            $version = (string)$module->version;
            if(!empty($version))
            {
                return $version;
            }
        }
    }
}

function check_module_version_vs_package_version($files, $extension_version)
{
    $configs = array();
    foreach($files as $file)
    {
        if(basename($file) == 'config.xml')
        {
            $configs[] = $file;
        }
    }

    foreach($configs as $file)
    {
        $xml = simplexml_load_file($file);
        $version_strings = $xml->xpath('//version');
        foreach($version_strings as $version)
        {
            if($version != $extension_version)
            {
                error(
                    "Extension Version [$extension_version] does not match " .
                    "module version [$version] found in a config.xml file.  Add " .
                    "'skip_version_compare'   => true  to configuration to skip this check."
                );
            }
        }
    }
}

function main($argv)
{
    $this_script = array_shift($argv);
    $config_file = array_shift($argv);
    $config = load_config($config_file);

    $base_dir           = $config['base_dir'];          //'/Users/alanstorm/Documents/github/Pulsestorm/var/build';
    $archive_files      = $config['archive_files'];     //'Pulsestorm_Modulelist.tar';
    $path_output        = $config['path_output'];       //'/Users/alanstorm/Desktop/working';
    $archive_connect    = $config['extension_name'] . '-' . $config['extension_version'] . '.tgz';

    $temp_dir   = get_temp_dir();
    if($base_dir['0'] !== '/')
    {
        $base_dir = getcwd() . '/' . $base_dir;
    }
    chdir($temp_dir);
    if(!file_exists($base_dir . '/' . $archive_files))
    {
        error('Can\'t find specified archive, bailing' . "\n[" . $base_dir . '/' . $archive_files.']');
        exit;
    }
    shell_exec('cp '        . $base_dir . '/' . $archive_files . ' ' . $temp_dir);
    if(preg_match('/\.zip$/', $archive_files)) {
        shell_exec('unzip -o '  . $temp_dir . '/' . $archive_files);
    } else {
        shell_exec('tar -xf '  . $temp_dir . '/' . $archive_files);
    }
    shell_exec('rm '        . $temp_dir . '/' . $archive_files);

    $all        = glob_recursive($temp_dir  . '/*');
    $dirs       = glob_recursive($temp_dir .'/*',GLOB_ONLYDIR);
    $files      = array_diff($all, $dirs);

    if(isset($config['auto_detect_version']) && $config['auto_detect_version'] == true)
    {
        $config['extension_version'] = get_module_version($files);
        $archive_connect = $config['extension_name'] . '-' . $config['extension_version'] . '.tgz';
    }

    if(!$config['skip_version_compare'])
    {
        check_module_version_vs_package_version($files, $config['extension_version']);
    }

    $xml        = create_package_xml($files,$temp_dir,$config);

    file_put_contents($temp_dir . '/package.xml',$xml);

    if(!is_dir($path_output))
    {
        mkdir($path_output, 0777, true);
    }

    $archiver = new Mage_Archive_Tar;
    $archiver->pack($temp_dir,$path_output.'/'.$archive_files,true);

    shell_exec('gzip '  . $path_output . '/' . $archive_files);
    shell_exec('mv '    . $path_output . '/' . $archive_files.'.gz '.$path_output.'/' . $archive_connect);
    // Creating extension xml for connect using the extension name
    create_extension_xml($files, $config, $temp_dir, $path_output);
}
/**
 * extrapolate the target module using the file absolute path
 * @param  string $filePath
 * @return string
 */
function extract_target($filePath)
{
    foreach (get_target_map() as $tMap) {
        $pattern = '#' . $tMap['path'] . '#';
        if (preg_match($pattern, $filePath)) {
            return $tMap['target'];
        }
    }
    return 'mage';
}
/**
 * get target map
 * @return array
 */
function get_target_map()
{
    return array(
        array('path' => 'app/etc', 'target' => 'mageetc'),
        array('path' => 'app/code/local', 'target' => 'magelocal'),
        array('path' => 'app/code/community', 'target' => 'magecommunity'),
        array('path' => 'app/code/core', 'target' => 'magecore'),
        array('path' => 'app/design', 'target' => 'magedesign'),
        array('path' => 'lib', 'target' => 'magelib'),
        array('path' => 'app/locale', 'target' => 'magelocale'),
        array('path' => 'media/', 'target' => 'magemedia'),
        array('path' => 'skin/', 'target' => 'mageskin'),
        array('path' => 'http://', 'target' => 'mageweb'),
        array('path' => 'https://', 'target' => 'mageweb'),
        array('path' => 'Test/', 'target' => 'magetest'),
    );
}
function create_extension_xml($files, $config, $tempDir, $path_output)
{
    $extensionPath = $tempDir . DIRECTORY_SEPARATOR . 'var/connect/';
    if (!is_dir($extensionPath)) {
        mkdir($extensionPath, 0777, true);
    }
    $extensionFileName = $extensionPath . $config['extension_name'] . '.xml';
    file_put_contents($extensionFileName, build_extension_xml($files, $config));
    shell_exec('cp -Rf '    . $tempDir . DIRECTORY_SEPARATOR . 'var '. $path_output);
}
function build_extension_xml($files, $config)
{
    $xml = simplexml_load_string('<_/>');
    $xml->addChild('form_key')->value = isset($config['form_key']) ? $config['form_key'] : uniqid();
    $xml->addChild('_create')->value = isset($config['_create']) ? $config['_create'] : '';
    $xml->addChild('name')->value = $config['extension_name'];
    $xml->addChild('channel')->value = $config['channel'];
    $versionIds = $xml->addChild('version_ids');
    $versionIds->addChild('version_ids')->value = 2;
    $versionIds->addChild('version_ids')->value = 1;
    $xml->addChild('summary')->value = $config['summary'];
    $xml->addChild('description')->value = $config['description'];
    $xml->addChild('license')->value = $config['license'];
    $xml->addChild('license_uri')->value = isset($config['license_uri']) ? $config['license_uri'] : '';
    $xml->addChild('version')->value = $config['extension_version'];
    $xml->addChild('stability')->value = $config['stability'];
    $xml->addChild('notes')->value = $config['notes'];

    $authors = $xml->addChild('authors');
    $authorName = $authors->addChild('name');
    $authorName->addChild('name')->value = $config['author_name'];

    $authorUser = $authors->addChild('user');
    $authorUser->addChild('user')->value = $config['author_user'];

    $authorEmail = $authors->addChild('email');
    $authorEmail->addChild('email')->value = $config['author_email'];

    $xml->addChild('depends_php_min')->value = $config['php_min'];
    $xml->addChild('depends_php_max')->value = $config['php_max'];

    $node = $xml->addChild('contents');
    $targetNode = $node->addChild('target');
    $pathNode = $node->addChild('path');
    $typeNode = $node->addChild('type');
    $includeNode = $node->addChild('include');
    $ignoreNode = $node->addChild('ignore');

    foreach ($files as $file) {
        $targetNode->addChild('target')->value = extract_target($file);
        $pathNode->addChild('path')->value = $file;
        $typeNode->addChild('type')->value = 'file';
        $includeNode->addChild('include');
        $ignoreNode->addChild('ignore');
    }

    return $xml->asXml();
}
main($argv);
