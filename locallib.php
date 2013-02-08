<?php

function api_call(array $params) {
    $apiurl   = get_config('block_my_courses', 'api_url');
    $username = get_config('block_my_courses', 'api_user');
    $password = get_config('block_my_courses', 'api_key');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    curl_setopt($ch, CURLOPT_URL, $apiurl.implode('/', $params));
    $curlcontents = curl_exec($ch);
    $curlheader  = curl_getinfo($ch);
    curl_close($ch);

    if ($curlheader['http_code'] == 200) {
        // Return fetched data
        return json_decode($curlcontents);

    } else {
        // Show error
        echo '<pre>';
        echo get_string('servererror', 'block_my_courses')."\n";
        if (!empty($curlheader['http_code'])) {
            echo get_string('curl_header', 'block_my_courses');
            echo ':';
            echo $curlheader['http_code']."\n";
        }
        echo 'URL: '.$apiurl.implode('/', $params);
        echo '</pre>';
    }
}
