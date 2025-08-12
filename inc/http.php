<?php
/**
 * Send an HTTP request to an API endpoint.
 *
 * @param string $method The HTTP method (GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD).
 * @param string $url The base URL of the API endpoint.
 * @param array|string|null $data (optional) The data to send in the body of the request.
 *                               Can be an array, JSON-encoded string, or null.
 * @param array $headers (optional) An associative array or indexed array of headers.
 * @param int $timeout (optional) The maximum number of seconds to allow cURL functions to execute.
 * @return array An array containing the response data and HTTP status code.
 */
function sendHttpRequest(string $method, string $url, $data = null, $headers = [], int $timeout = 30): array {
    // Initialize cURL session
    $ch = curl_init();

    // Encode data as JSON if sending data and $data is an array or JSON string
    if (!is_null($data)) {
        if (is_array($data)) {
            if ($method === 'GET') {
                // Check if URL already has query parameters
                $querySeparator = strpos($url, '?') === false ? '?' : '&';
                $url .= $querySeparator . http_build_query($data);
            } else {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                // If headers are associative, add Content-Type header
                if (is_array($headers) && !isset($headers[0])) {
                    $headers['Content-Type'] = 'application/json';
                }
            }
        } elseif (is_string($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    // Convert headers to the appropriate format if they are associative
    if (is_array($headers) && !isset($headers[0])) {
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }
    } else {
        $curlHeaders = $headers;
    }

    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    // Execute cURL request
    $response = curl_exec($ch);

    // Handle errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'success' => false,
            'error' => $error,
            'data' => null,
            'http_code' => 0
        ];
    }

    // Get HTTP status code
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($ch);

    // Parse response
    $responseData = json_decode($response, true);

    return [
        'success' => true,
        'error' => null,
        'data' => $responseData,
        'http_code' => $httpCode
    ];
}
