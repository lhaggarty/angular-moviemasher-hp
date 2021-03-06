<?php /*
This script is called from the CGI control, after loading export_init.php.
The job and media IDs are in _GET.
The script attempts to check on the progress of the job and either:
	* redirects back to itself, if job is still processing, by setting 'url' attribute
	* directs browser to refresh browser view if job is finished
	* displays javascript alert if error is encountered, by setting 'get' attribute
If possible, the response to client is logged.
*/

$response = array();
$log_responses = '';
$err = '';
$config = array();
$done = FALSE;

if (! @include_once(dirname(__FILE__) . '/include/loadutils.php')) $err = 'Problem loading utility script';
if ((! $err) && (! load_utils('api','data'))) $err = 'Problem loading utility scripts';

if (! $err) { // pull in configuration so we can log other errors
	$config = config_get();
	$err = config_error($config);
	$log_responses = $config['log_response'];
}
if (! $err) { // see if the user is authenticated (does not redirect or exit)
	if (! auth_ok()) $err = 'Unauthenticated access';
}
if (! $err) { // pull in other configuration and check for required input
	if (! $php_input = file_get_contents('php://input')) $err = 'JSON payload required';
	else if (! $request = @json_decode($php_input, TRUE)) $err = 'Could not parse JSON payload';
}
if (! $err) { 
	if ($config['log_request']) log_file(print_r($request, 1), $config);
	$job = (empty($request['job']) ? '' : $request['job']);
	if (! $job) $err = 'Parameter job required';
}
if (! $err) { // get job progress
	$progress_file = path_concat($config['temporary_directory'], $job . '.json');
	$json_str = file_get($progress_file);
	if (! $json_str) { // no progress file created yet
		$response['completed'] = 0.01;
		$response['status'] = 'Queued';
	} else {
		if ($config['log_api_response']) log_file($json_str, $config);
		$json_object = @json_decode($json_str, TRUE);
		if (! is_array($json_object)) $err = 'Could not parse response';
		if (! $err) {
			if (! empty($json_object['error'])) $err = $json_object['error'];
		}
		if (! $err) {
			if (! empty($json_object['id'])) {  
				$export_data = api_export_data($json_object, $config);
				if (! empty($export_data['error'])) $err = $export_data['error'];
				else $err = data_update_mash($export_data, $json_object['uid'], $config);
				$response['completed'] = 1;
				$json_object['source'] = $export_data['source'];
			} else {
				if (empty($json_object['completed'])) {
					$json_object['completed'] = 0;
					if (! empty($json_object['progress'])) {
						$json_object['completed'] = api_progress_completed($json_object['progress']);
					}
				}
				if (! $json_object['completed']) $err = 'Could not find progress or completed in response';
				else {
					$response['completed'] = $json_object['completed'];
					if (empty($json_object['status'])) $json_object['status'] = floor($json_object['completed'] * 100) . '%';			
					$response['status'] = $json_object['status'];
				}
			}
			$done = (1 == $response['completed']);
			if ($done) $response['source'] = (empty($json_object['source']) ? '' : $json_object['source']);
		}
	}
}
if ($done || $err) @unlink($progress_file);
if ($err) $response['error'] = $err;
else $response['ok'] = 1;
$json = json_encode($response);
print $json . "\n\n";
if ($log_responses) log_file($json, $config);
