<?php

namespace Tanios\ContentfulWp;

class API {

	/**
	 * @var string Contentful Access Token
	 */
	private $access_token;

	/**
	 * Creates an instance of Contentful API
	 * @param string $access_token Contentful Access Token
	 */
	public function __construct( $access_token )
	{

		//setting up access token
		$this->access_token = $access_token;

	}

	/**
	 * Makes an API request to Contentful
	 * @param string $endpoint Endpoint path
	 * @param string $method Method of the request
	 * @param array $params Parameters in a key => value format
	 * @throws \Exception
	 * @return \stdClass
	 */
	public function request( $endpoint, $method, $params = array(), $headers = array() )
	{

		//if no access token found
		if( ! $this->access_token || $this->access_token == -1 )
		{
			//throwing an error
			throw new \Exception( __( "Cannot make the API request because there is no access token specified.", Plugin::TEXTDOMAIN ) );
		}

		//removing first slash of the endpoint if present
		if( substr( $endpoint, 0, 1 ) == '/' )
		{
			$endpoint = substr( $endpoint, 1, strlen( $endpoint ) - 1 );
		}

		//formating parameters into JSON format
		$params = json_encode( $params );

		//creating a CURL instance
		$c = curl_init();

		//passing CURL parameters to the instance
		curl_setopt_array( $c, array(
			CURLOPT_URL             => "https://api.contentful.com/$endpoint", //api url + endpoint,
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_POST            => strtolower( $method ) == 'post',
			CURLOPT_HEADER          => false,
			CURLOPT_HTTPHEADER      => array_merge( $headers, array(
				"Authorization: Bearer $this->access_token",
				"Content-Type: application/vnd.contentful.management.v1+json"
			) ),
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_POSTFIELDS      => $params
		) );

		//executing the CURL request
		$response = curl_exec( $c );

		//checking for errors
		if( curl_errno( $c ) )
		{
			//throwing an exception with the error message
			throw new \Exception( curl_error( $c ) );
		}

		//otherwise, decoding JSON response
		$response = json_decode( $response );

		//closing curl
		curl_close( $c );

		//returing response
		return $response;

	}

}