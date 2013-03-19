#!/usr/bin/php
<?php

/*

Purpose: to use change the keys used in a PrestaShop translation file.

Usage: change_keys.php iso.gzip (en.gzip | changes.csv)*

Where iso.gzip is the pack to change keys of and changes.csv is a CSV file (with headers 'Old Key' and 'New English Text' at least).

The resulting pack will have *both* the old and the new keys, any superfluous translations will be removed after the translation admin tab
is visited from PS BO and the translations are submitted once.

*/

include_once 'Archive/Tar.php'; 

@ini_set('display_errors', 'on');

function error_handler($errNo, $errStr, $errFile, $errLine)
{
	$msg = "$errStr in $errFile on line $errLine";
	throw new ErrorException($msg, $errNo);
}
set_error_handler('error_handler');

/************************************************/
/*				HELPER FUNCTIONS 				*/
/************************************************/

//create a temporary directory
//warning, subject to tiny race condition, couldn't find any better
function tempdir()
{
    $tempfile=tempnam(sys_get_temp_dir(),'');
    if(file_exists($tempfile))
    { 
    	unlink($tempfile);
    	mkdir($tempfile);
    }

    if(is_dir($tempfile))return $tempfile;
    else return false;
}

//recursively delete directory
function rrmdir($dir) 
{ 
   if(is_dir($dir)) 
   { 
	     $objects = scandir($dir); 
	     foreach ($objects as $object)
	     { 
	       	if ($object != "." && $object != "..")
	       	{ 
	         	if (filetype($dir."/".$object) == "dir")rrmdir($dir."/".$object); 
	         	else unlink($dir."/".$object); 
	       	} 
    	} 
    	reset($objects); 
    	rmdir($dir); 
   } 
 }


//file put contents with directory structure creation
function file_put_contents_with_parents($path, $data)
{
	$dir = dirname($path);
	if(!is_dir($dir))
	{
		mkdir($dir, 0777, true);
	}
	file_put_contents($path, $data);
}

//return an array with the (recursive) list of files in a directory with full path
function file_list($dir)
{
	$files = array();
	//lol, php
	foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $path)
	{
		if(is_file($path) and !is_dir($path))$files[] = $path;
	}
	return $files;
}

//like addslashes but removes superfluous backslashes before adding them!
function slashify($str)
{
	return preg_replace('/\\\\*([\'])/', "\\\\$1", $str);
}

function getOpts()
{
	global $argv;
	$options = array();
	$n       = count($argv);

	for($i=1; $i < $n; $i+=1)
	{
		$m = array();
		if(preg_match('/^--(.*)$/', $argv[$i], $m))
		{
			unset($argv[$i]);
			$i+=1;
			if($i < $n)
			{
				$options[$m[1]] = $argv[$i];
				unset($argv[$i]);
			}
			else 
			{
				$options[$m[1]] = null;
			}
		}
	}

	return $options;
}

/************************************************/
/************************************************/
/************************************************/

/************************************************/
/*		    REAL STUFF STARTS HERE   			*/
/************************************************/


$options = getOpts();

//check the arguments
if(count($argv) < 2)
{
	echo "Usage: " . basename($argv[0]) . " gzip_to_modify [english_gzip_or_csv_to_use ...] [--tpl template]\n";
	exit;
}

//the file we want to change the keys of
$target 	= $argv[1];

if(!file_exists($target))
{
	echo "The file '$target' doesn't seem to exist!\n";
	exit;
}

//file by file key changes, pupulated using en_xxx.gzip packs
$en_changes_in_files = array();
//global key changes (no file by file check is performed)
$en_uncontextualized_changes = array();
//count the number of English keys we want to change
$en_count   = 0;

//takes a GZIP, returns an array with translation keys as keys and translations as values
function getGZIPKeyToTranslationDictionary($src)
{
	$k2t   = array();
	$dir   = tempdir(); 
	$gzip  = new Archive_Tar($src);
	$gzip->extract($dir);
	foreach($gzip->listContent() as $desc)
	{
		$filename = $desc['filename'];
		$path     = "$dir/$filename";
		$source   = file_get_contents($path);
		$m = array();
		if(preg_match('/\.php$/', $filename) and basename($filename) != 'index.php' and preg_match('/global\s+\$(_\w+)/', $source, $m))
		{
			$arr = $m[1];
			include_once($path);
			foreach($$arr as $key => $value)
			{
				$k2t[$key] = $value;
			}
		}
	}
	rrmdir($dir);
	return $k2t;
}

//read a GZIP (en) file and stores the key changes in it by comparing the new string's md5 with the old md5
function readGZIPChanges($src, &$en_changes_in_files, &$k2e)
{
	$total = 0;
	$en_gzip = new Archive_Tar($src);
	$dir   = tempdir(); 
	// for some reason Archive_Tar's extractInString is painfully slow on some platforms, so we extract in a temporary dir
	$en_gzip->extract($dir);
	foreach($en_gzip->listContent() as $desc)
	{
		$filename = $desc['filename'];
		$path     = "$dir/$filename";
		$source   = file_get_contents($path);
		$m = array();
		if(preg_match('/\.php$/', $filename) and basename($filename) != 'index.php' and preg_match('/global\s+\$(_\w+)/', $source, $m))
		{
			$arr = $m[1];
			//echo "Including $filename to extract array $arr\n";
			
			include_once($path);
			if(!isset($en_changes_in_files[$filename]))
			{
				$en_changes_in_files[$filename] = array();
			}
			foreach($$arr as $key => $value)
			{
				$m = array();
				if(preg_match('/^(.*?)([a-f0-9]{32})$/', $key, $m))
				{
					$md5 = $m[2];

					$new_md5 = md5(slashify($value));
					if($md5 != $new_md5)
					{
						//$en_changes_in_files[$filename][$key] is an array because we allow to change a key with several substitutes
						if(!isset($en_changes_in_files[$filename][$key]))$en_changes_in_files[$filename][$key] = array();
						$new_key = $m[1] . $new_md5;
						$en_changes_in_files[$filename][$key][$new_key] = 0; //use count of this new key
						$k2e[$new_key] = $value;
					}
				}
			}
			$count  = count($en_changes_in_files[$filename]);
			$total += $count;
			//echo "$count of " . count($$arr) . " English strings were changed in the file $filename!\n\n";
		}
	}
	rrmdir($dir);
	return $total;
}

function CSVForEach($file, $func)
{
	$f = fopen($file, 'r');
	$first_line = fgets($f);
	rewind($f);

	//guess separator
	if(substr_count($first_line, ";") > substr_count($first_line, ","))
	{
		$separator=";";
	}
	else
	{
		$separator=",";
	}

	$headers = fgetcsv($f, 0, $separator);

	while($row = fgetcsv($f, 0, $separator))
	{
		$row = array_combine($headers, $row);
		$func($row);
	}

	fclose($f);
}

function getCSVChanges($src)
{
	$contextualized 	= array();
	$uncontextualized 	= array();
	$k2e                = array();

	CSVForEach($src, function($row) use(&$contextualized, &$uncontextualized, &$k2e){

		$get = function($key, $default = false) use ($row){
			if(isset($row[$key]) and $row[$key] != null and $row[$key] != '')
			{
				return $row[$key];
			}
			else return false;
		};

		if(false !== ($old_key = $get('Old Key')))
		{
			if(false !== ($new_key = $get('New Key')))
			{
				//OK!!
			}
			else if(false !== ($new_english_text = $get('New English Text')))
			{
				$m = array();
				if(preg_match('/^(.*?)([a-f0-9]{32})$/', $old_key, $m))
				{
					$new_key = $m[1] . md5(slashify($new_english_text));
				}
			}

			if($old_key !== false and $new_key !== false)
			{
				if(false !== ($filepath = $get('File Path')))
				{
					if(!isset($contextualized[$filepath]))
					{
						$contextualized[$filepath] = array();
					}
					if(!isset($contextualized[$filepath][$old_key]))
					{
						$contextualized[$filepath][$old_key] = array();
					}
					$contextualized[$filepath][$old_key][$new_key] = 0;
				}
				else
				{
					if(isset($uncontextualized[$old_key]))
					{
						$uncontextualized[$old_key] = array();
					}
					$uncontextualized[$old_key][$new_key] = 0;
				}

				if(false !== ($new_english_text = $get('New English Text')))
				{
					$k2e[$new_key] = $new_english_text;
				}

			}
			else
			{
				die("Oops!\n");
			}

		}
	});
	return array('contextualized' => $contextualized, 'uncontextualized' => $uncontextualized, 'k2e' => $k2e);
}

function count_leaves($arr)
{
	$count = 0;
	foreach($arr as $key => $value)
	{
		if(is_array($value))
		{
			$count += count_leaves($value);
		}
		else
		{
			$count += 1;
		}
	}
	return $count;
}

//reads changes from either CSV file or en_xxx.gzip
function readChanges($src, &$en_changes_in_files, &$en_uncontextualized_changes, &$k2e)
{
	if(!file_exists($src))
	{
		echo "Oops: file '$src' doesn't exist!\n";
		exit;
	}

	if(preg_match('/^en(?:_.*?)?\.gzip$/', basename($src)))
	{
		echo "Found GZIP $src!\n";
		return readGZIPChanges($src,$en_changes_in_files, $k2e);
	}
	else if(preg_match('/\.csv$/', $src))
	{
		$changes = getCSVChanges($src);
		$en_changes_in_files 			= array_merge_recursive($en_changes_in_files, $changes['contextualized']);
		$en_uncontextualized_changes 	= array_merge_recursive($en_uncontextualized_changes, $changes['uncontextualized']);
		$k2e                 		    = array_merge($k2e, $changes['k2e']);

		return count_leaves($changes);
	}
	else
	{
		echo "Unsupported file: '$src' :(\n";
		exit;
	}
}

//Key to English
$k2e_dictionary = array();
//reads $argv for key change files (starting at index 2)
$en_count = 0;
for($i = 2; $i < count($argv); $i+=1)
{
	$en_count += readChanges($argv[$i], $en_changes_in_files, $en_uncontextualized_changes, $k2e_dictionary);
}	

//the iso code of our target pack
$iso = basename($target, ".gzip");


//File to Key ([filename => [key1, ..., keyN]])
$f2k_dictionary = array();
//Key to Translation
$k2t_dictionary = array();

if(isset($options['tpl']))
{
	CSVForEach($options['tpl'], function($row) use (&$k2e_dictionary){
		$k2e_dictionary[$row['Array Key']] = $row['English String'];
	});

	CSVForEach($options['tpl'], function($row) use (&$f2k_dictionary, $iso){
		if($row['Array Name'])
		{
			$file = str_replace('/en.php', "/$iso.php", str_replace('/en/', "/$iso/", substr($row['Storage File Path'],1)));
			if(!isset($f2k_dictionary[$file]))
			{
				$f2k_dictionary[$file] = array();
			}
			$f2k_dictionary[$file][] = $row['Array Key'];
		}
	});

	$k2t_dictionary = getGZIPKeyToTranslationDictionary($target);
}

$e2t_dictionary = array();
foreach($k2e_dictionary as $key => $english)
{
	if(isset($k2t_dictionary[$key]))
	{
		$e2t_dictionary[$english] = $k2t_dictionary[$key];
	}
}

//output file, in current directory (warning!!)
$dst = "$iso.gzip";
if(file_exists($dst))
{
	unlink($dst);
}

$dir     = tempdir();
$out_dir = tempdir();

$target_gzip = new Archive_Tar($target);

$target_gzip->extract($dir);

$autofilled = 0;

$missing = 0;
$total   = 0;

$missing_translations = array();

foreach($target_gzip->listContent() as $desc)
{
	$filename = $desc['filename'];
	$path     = "$dir/$filename";
	//echo "Changing keys in file $filename...\n";

	$en_filename = str_replace("/$iso.php", '/en.php', str_replace("/$iso/", '/en/', $filename));
	
	$source = file_get_contents($path);

	$m = array();
	if(preg_match('/global\s+\$(_\w+)/', $source, $m))
	{
		$nreplaced   =  0;
		$arr = $m[1];
		include_once($path);
		$lang = $$arr;

		if(isset($en_changes_in_files[$en_filename]))
		{	
			foreach($en_changes_in_files[$en_filename] as $old_key => &$new_keys_0)
			{
				foreach($new_keys_0 as $new_key => &$use_count_0)
				{
					if(isset($lang[$old_key]))
					{
						$use_count_0 += 1;

						//do not overwrite if the new key is already set
						if(!isset($lang[$new_key]))
						{
							$lang[$new_key] = $lang[$old_key];
							$nreplaced += 1;
						}
					}
				}
			}
			
		}

		foreach($en_uncontextualized_changes as $old_key => &$new_keys_1)
		{
			foreach($new_keys_1 as $new_key => &$use_count_1)
			{
				/*
				if($new_key == 'AdminPerformance565cd4c2da13cbbff2415a0e81e6c391')
				{
					die("" . "\nbob\n");
				}*/

				if(isset($lang[$old_key]))
				{
					$use_count_1 += 1;

					//do not overwrite if the new key is already set
					if(!isset($lang[$new_key]))
					{
						$lang[$new_key] = $lang[$old_key];
						$nreplaced += 1;
					}
				}
			}		
		}

		$source  = "<?php\n\nglobal \$$arr;\n\$$arr = array();";

		$missing_in_file = 0;
		$total_in_file   = 0;
		if(isset($options['tpl']))
		{
			if(isset($f2k_dictionary[$filename]))
			{
				foreach($f2k_dictionary[$filename] as $key)
				{
					if(!isset($options['tpl']) or isset($k2e_dictionary[$key]))
					{
						if(!isset($lang[$key]))
						{
							if(isset($e2t_dictionary[$k2e_dictionary[$key]]))
							{
								//echo $k2e_dictionary[$key] . " => " . $e2t_dictionary[$k2e_dictionary[$key]] . "\n";
								$lang[$key] = $e2t_dictionary[$k2e_dictionary[$key]];
								$autofilled += 1;
							}
							else
							{
								$missing_translations[$key] = $k2e_dictionary[$key];
								$missing_in_file+=1;
								$missing += 1;
							}

						}
						$total_in_file += 1;
						$total += 1;
					}
				}
			}
		}
		
		echo "File $filename: missing $missing_in_file of $total_in_file.\n";

		foreach($lang as $key => $translation)
		{
			//remove useless keys if template was specified
			if(!isset($options['tpl']) or isset($k2e_dictionary[$key]))
			{
				$source .= "\n\$$arr"."['" . slashify($key) . "'] = '". slashify($translation) . "';";
			}
		}
		$source .= "\n";

		//echo "Changed $nreplaced keys in file $filename!\n\n";
	}

	//$new_target_gzip->addString($filename, $source);
	file_put_contents_with_parents("$out_dir/$filename", $source);

}
//remove dir where we extracted the target gzip
rrmdir($dir);

//create our output archive
$new_target_gzip = new Archive_Tar($dst, 'gz');
$new_target_gzip->createModify(file_list($out_dir),'',$out_dir);
//remove tmp output dir
rrmdir($out_dir);

//see what happened
$count         = 0;
$show_not_used = function($arr) use (&$count)
{
	foreach($arr as $old_key => $new_keys)
	{
		$used = false;
		foreach($new_keys as $new_key => $use_count)
		{
			if($use_count > 0)
			{
				$used   = true;
				$count += 1;
				break;
			}
		}
		if(!$used)
		{
			echo "Key $old_key was never found, so not replaced!\n";
		}
	}
};

foreach($en_changes_in_files as $file => $changes)
{
	$show_not_used($changes);
}

$show_not_used($en_uncontextualized_changes);

echo "\nChanged $count keys in '$target' (of $en_count).\n";
echo "\n$autofilled translations were autocompleted.\n";


//print_r($en_uncontextualized_changes['AdminPerformanceea3552401a65fd61c45745b3345b12f0']);

if(isset($options['dump-changes']))
{
	$out = $options['dump-changes'];
	$headers = array('File Path', 'Old Key', 'New Key', 'New English Text', 'Use Count');
	$f = fopen($out, 'w');
	fputcsv($f, $headers);
	
	foreach($en_changes_in_files as $file => $arr)
	{
		foreach($arr as $old_key => $new_keys)
		{
			foreach($new_keys as $new_key => $use_count)
			{
				$row = array($file, $old_key, $new_key, isset($k2e_dictionary[$new_key]) ? $k2e_dictionary[$new_key] : '', $use_count);
				fputcsv($f, $row);
			}
		}
	}
	/*echo "???? =>\n";
	print_r($en_uncontextualized_changes['AdminPerformanceea3552401a65fd61c45745b3345b12f0']);*/

	foreach($en_uncontextualized_changes as $old_key => $new_keys)
	{
		foreach($new_keys as $new_key => $use_count)
		{
			/*if(!isset($options['tpl']) or(isset($k2e_dictionary[$new_key])))
			{*/
				$row = array('', $old_key, $new_key, isset($k2e_dictionary[$new_key]) ? $k2e_dictionary[$new_key] : '', $use_count);
				fputcsv($f, $row);
			//}
		}
	}

	fclose($f);
}

if(isset($options['dump-missing']))
{
	$out = $options['dump-missing'];
	$f 	 = fopen($out, 'w');

	fputcsv($f, array('Key', 'English String'));

	foreach($missing_translations as $key => $value)
	{
		fputcsv($f, array($key, $value));
	}

	fclose($f);
}

if(isset($options['tpl']))
{
	echo "Missing $missing of $total translations.\n";
}

//that's all folks!
