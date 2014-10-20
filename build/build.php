<?php
/**
 * Script used to build Joomla distribution archive packages
 * Builds upgrade packages in tmp/packagesx.x folder (for example, 'build/tmp/packages2.5')
 * Builds full packages in tmp/packages_fullx.x.x folder (for example, 'build/tmp/packages_full2.5.1')
 *
 * Note: the new package must be tagged in your git repository BEFORE doing this
 * It uses the git tag for the new version, not trunk.
 *
 * This script is designed to be run in CLI on Linux or Mac OS X.
 * Make sure your default umask is 022 to create archives with correct permissions.
 *
 * Steps:
 * 1. Tag new release in the local git repository (for example, "git tag 2.5.1")
 * 2. Set the $version and $release variables for the new version.
 * 3. Run from CLI as: 'php build.php" from build directory.
 * 4. Check the archives in the tmp directory.
 *
 * @package		Joomla.Build
 *
 * @copyright	Copyright (C) 2005 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license		GNU General Public License version 2 or later; see LICENSE.txt
 */

// Set path to git binary (e.g., /usr/local/git/bin/git or /usr/bin/git)
ob_start();
passthru('which git', $systemGit);
$systemGit = ob_get_clean();
$gitPath = '/usr/bin/git';

// Sanity check - Make sure $gitPath is the same path the system recognizes
if (substr($systemGit, 0, -1) != $gitPath)
{
	echo '$gitPath does not match path to local git executable, please set $gitPath to: ' . substr($systemGit, 0, -1) . "\n";
	exit;
}

// Make sure file and folder permissions are set correctly
umask(022);

// Import JVersion to set the version information
define('JPATH_PLATFORM', 1);
require_once dirname(__DIR__) . '/libraries/cms/version/version.php';
$jversion = new JVersion;

// Set version information for the build
$version     = $jversion->RELEASE;
$release     = $jversion->DEV_LEVEL;
$stability   = $jversion->DEV_STATUS;
$fullVersion = $version . '.' . $release;

// Shortcut the paths to the repository root and build folder
$repo = dirname(__DIR__);
$here = __DIR__;

// Set paths for the build packages
$tmp      = $here . '/tmp';
$fullpath = $tmp . '/' . $fullVersion;

echo "Start build for version $fullVersion.\n";
echo "Delete old release folder.\n";
system('rm -rf ' . $tmp);
mkdir($tmp);
mkdir($fullpath);

echo "Copy the files from the git repository.\n";
chdir($repo);
system($gitPath . ' archive ' . $fullVersion . ' | tar -x -C ' . $fullpath);

chdir($tmp);
system('mkdir diffdocs');
system('mkdir diffconvert');
system('mkdir packages' . $version);

echo "Copy manifest file to root directory for install packages.\n";
system('cp ' . $fullpath . '/administrator/manifests/files/joomla.xml ' . $fullpath);

echo "Create list of changed files from git repository.\n";

/*
 * Here we force add every top-level directory and file in our diff archive, even if they haven't changed.
 * This allows us to install these files from the Extension Manager.
 * So we add the index file for each top-level directory.
 * Note: If we add new top-level directories or files, be sure to include them here.
 */
$filesArray = array(
	"administrator/index.php\n" => true,
	"bin/index.html\n" => true,
	"cache/index.html\n" => true,
	"cli/index.html\n" => true,
	"components/index.html\n" => true,
	"images/index.html\n" => true,
	"includes/index.html\n" => true,
	"language/index.html\n" => true,
	"layouts/index.html\n" => true,
	"libraries/index.html\n" => true,
	"logs/index.html\n" => true,
	"media/index.html\n" => true,
	"modules/index.html\n" => true,
	"plugins/index.html\n" => true,
	"templates/index.html\n" => true,
	"tmp/index.html\n" => true,
	"htaccess.txt\n" => true,
	"index.php\n" => true,
	"LICENSE.txt\n" => true,
	"README.txt\n" => true,
	"robots.txt.dist\n" => true,
	"web.config.txt\n" => true,
	"joomla.xml\n" => true
);

/*
 * Here we set the files/folders which should not be packaged at any time
 * These paths are from the repository root without the leading slash
 */
$doNotPackage = array(
	'.gitignore',
	'.travis.yml',
	'CONTRIBUTING.md',
	'README.md',
	'build',
	'build.xml',
	'composer.json',
	'composer.lock',
	'phpunit.xml.dist',
	'tests',
	'travisci-phpunit.xml',
);

/*
 * Here we set the files/folders which should not be packaged with patch packages only
 * These paths are from the repository root without the leading slash
 */
$doNotPatch = array(
	'installation',
    'images'
);

// For the packages, replace spaces in stability (RC) with underscores
$packageStability = str_replace(' ', '_', $stability);

// Count down starting with the latest release and add diff files to this array
for ($num = $release - 1; $num >= 0; $num--)
{
	echo "Create version $num update packages.\n";

	// Here we get a list of all files that have changed between the two tags ($previousTag and $fullVersion) and save in diffdocs
	$previousTag = $version . '.' . $num;
	$command     = $gitPath . ' diff tags/' . $previousTag . ' tags/' . $fullVersion . ' --name-status > diffdocs/' . $version . '.' . $num;

	system($command);

	// $filesArray will hold the array of files to include in diff package
	$deletedFiles = array();
	$files        = file('diffdocs/' . $version . '.' . $num);

	// Loop through and add all files except: tests, installation, build, .git, .travis, travis, phpunit, .md, or images
	foreach ($files as $file)
	{
		$fileName   = substr($file, 2);
		$folderPath = explode('/', $file);
		$folderName = $folderPath[0];

		// TODO - Old check, commented for reference, remove when complete
		/*if (substr($file, 2, 5) != 'tests' && substr($file, 2, 12) != 'installation' && substr($file, 2, 5) != 'build' && substr($file, 2, 4) != '.git'
			&& substr($file, 2, 7) != '.travis' && substr($file, 2, 6) != 'travis' && substr($file, 2, 7) != 'phpunit' && substr($file, -3) != '.md'
			&& substr($file, 2, 6) != 'images')
		{*/

		if (!in_array($fileName, $doNotPackage) && !in_array($fileName, $doNotPatch)
			&& !in_array($folderName, $doNotPackage) && !in_array($folderName, $doNotPatch))
		{
			// Don't add deleted files to the list
			if (substr($file, 0, 1) != 'D')
			{
				$filesArray[$fileName] = true;
			}
			else
			{
				// Add deleted files to the deleted files list
				$deletedFiles[] = $fileName;
			}
		}
	}

	// Write the file list to a text file.
	$filePut = array_keys($filesArray);
	sort($filePut);
	file_put_contents('diffconvert/' . $version . '.' . $num, implode('', $filePut));
	file_put_contents('diffconvert/' . $version . '.' . $num . '-deleted', $deletedFiles);

	// Only create archives for 0 and most recent versions. Skip other update versions.
	if ($num != 0 && ($num != $release - 1))
	{
		echo "Skipping patch archive for version $version.$num\n";

		continue;
	}

	$fromName = $num == 0 ? 'x' : $num;
	// Create the diff archive packages using the file name list.
	system('tar --create --bzip2 --no-recursion --directory ' . $fullVersion . ' --file packages' . $version . '/Joomla_' . $version . '.' . $fromName . '_to_' . $fullVersion . '-' . $packageStability . '-Patch_Package.tar.bz2 --files-from diffconvert/' . $version . '.' . $num . '> /dev/null');
	system('tar --create --gzip  --no-recursion --directory ' . $fullVersion . ' --file packages' . $version . '/Joomla_' . $version . '.' . $fromName . '_to_' . $fullVersion . '-' . $packageStability . '-Patch_Package.tar.gz  --files-from diffconvert/' . $version . '.' . $num . '> /dev/null');

	chdir($fullVersion);
	system('zip ../packages' . $version . '/Joomla_' . $version . '.' . $fromName . '_to_' . $fullVersion . '-' . $packageStability . '-Patch_Package.zip -@ < ../diffconvert/' . $version . '.' . $num . '> /dev/null');
	chdir('..');
}

// Delete the files and folders we exclude from the packages (tests, docs, build, etc.).
echo "Delete folders not included in packages.\n";

foreach ($doNotPackage as $removeFile)
{
	system('rm -rf ' . $fullVersion . '/' . $removeFile);
}

// Recreate empty directories before creating new archives.
system('mkdir packages_full' . $fullVersion);
echo "Build full package files.\n";
chdir($fullVersion);

// The weblinks package manifest should not be present for new installs, temporarily move it
system('mv administrator/manifests/packages/pkg_weblinks.xml ../pkg_weblinks.xml');

// Create full archive packages.
system('tar --create --bzip2 --file ../packages_full' . $fullVersion . '/Joomla_' . $fullVersion . '-' . $packageStability . '-Full_Package.tar.bz2 * > /dev/null');

system('tar --create --gzip --file ../packages_full' . $fullVersion . '/Joomla_' . $fullVersion . '-' . $packageStability . '-Full_Package.tar.gz * > /dev/null');

system('zip -r ../packages_full' . $fullVersion . '/Joomla_' . $fullVersion . '-' . $packageStability . '-Full_Package.zip * > /dev/null');

// Create full update file without installation folder or sample images.
echo "Build full update package.\n";
system('rm -r installation');
system('rm -r images/banners');
system('rm -r images/headers');
system('rm -r images/sampledata');
system('rm images/joomla_black.gif');
system('rm images/joomla_green.gif');
system('rm images/joomla_logo_black.jpg');
system('rm images/powered_by.png');

// Move the weblinks manifest back
system('mv ../pkg_weblinks.xml administrator/manifests/packages/pkg_weblinks.xml');

system('tar --create --bzip2 --file ../packages_full' . $fullVersion . '/Joomla_' . $fullVersion . '-' . $packageStability . '-Update_Package.tar.bz2 * > /dev/null');

system('tar --create --gzip --file ../packages_full' . $fullVersion . '/Joomla_' . $fullVersion . '-' . $packageStability . '-Update_Package.tar.gz * > /dev/null');

system('zip -r ../packages_full' . $fullVersion . '/Joomla_' . $fullVersion . '-' . $packageStability . '-Update_Package.zip * > /dev/null');

echo "Build of version $fullVersion complete!\n";
