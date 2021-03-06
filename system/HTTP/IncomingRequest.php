<?php namespace CodeIgniter\HTTP;

/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014 - 2016, British Columbia Institute of Technology
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package      CodeIgniter
 * @author       CodeIgniter Dev Team
 * @copyright    Copyright (c) 2014 - 2016, British Columbia Institute of Technology (http://bcit.ca/)
 * @license      http://opensource.org/licenses/MIT	MIT License
 * @link         http://codeigniter.com
 * @since        Version 3.0.0
 * @filesource
 */
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Services;

/**
 * Class IncomingRequest
 *
 * Represents an incoming, getServer-side HTTP request.
 *
 * Per the HTTP specification, this interface includes properties for
 * each of the following:
 *
 * - Protocol version
 * - HTTP method
 * - URI
 * - Headers
 * - Message body
 *
 * Additionally, it encapsulates all data as it has arrived to the
 * application from the CGI and/or PHP environment, including:
 *
 * - The values represented in $_SERVER.
 * - Any cookies provided (generally via $_COOKIE)
 * - Query string arguments (generally via $_GET, or as parsed via parse_str())
 * - Upload files, if any (as represented by $_FILES)
 * - Deserialized body binds (generally from $_POST)
 *
 * @package CodeIgniter\HTTPLite
 */
class IncomingRequest extends Request
{

	/**
	 * Parsed input stream data
	 *
	 * Parsed from php://input at runtime
	 *
	 * @var
	 */
	protected $inputStream;

	/**
	 * Enable CSRF flag
	 *
	 * Enables a CSRF cookie token to be set.
	 * Set automatically based on Config setting.
	 *
	 * @var bool
	 */
	protected $enableCSRF = false;

	/**
	 * A \CodeIgniter\HTTPLite\URI instance.
	 *
	 * @var URI
	 */
	public $uri;

	/**
	 * Set a cookie name prefix if you need to avoid collisions
	 *
	 * @var string
	 */
	protected $cookiePrefix = '';

	/**
	 * Set to .your-domain.com for site-wide cookies
	 *
	 * @var string
	 */
	protected $cookieDomain = '';

	/**
	 * Typically will be a forward slash
	 *
	 * @var string
	 */
	protected $cookiePath = '/';

	/**
	 * Cookie will only be set if a secure HTTPS connection exists.
	 *
	 * @var bool
	 */
	protected $cookieSecure = false;

	/**
	 * Cookie will only be accessible via HTTP(S) (no javascript)
	 *
	 * @var bool
	 */
	protected $cookieHTTPOnly = false;

	/**
	 * @var Files\FileCollection
	 */
	protected $files;

	/**
	 * @var \CodeIgniter\HTTP\Negotiate
	 */
	protected $negotiate;

	//--------------------------------------------------------------------

	public function __construct($config, $uri = null, $body = 'php://input')
	{
		// Get our body from php://input
		if ($body == 'php://input')
		{
			$body = file_get_contents('php://input');
		}

		$this->body = $body;

		parent::__construct($config, $uri);

		$this->populateHeaders();

		$this->uri = $uri;

		$this->detectURI($config->uriProtocol, $config->baseURL);

		$this->cookiePrefix   = $config->cookiePrefix;
		$this->cookieDomain   = $config->cookieDomain;
		$this->cookiePath     = $config->cookiePath;
		$this->cookieSecure   = $config->cookieSecure;
		$this->cookieHTTPOnly = $config->cookieHTTPOnly;
	}

	//--------------------------------------------------------------------

	/**
	 * Determines if this request was made from the command line (CLI).
	 *
	 * @return bool
	 */
	public function isCLI(): bool
	{
		return (PHP_SAPI === 'cli' || defined('STDIN'));
	}

	//--------------------------------------------------------------------

	/**
	 * Test to see if a request contains the HTTP_X_REQUESTED_WITH header.
	 *
	 * @return bool
	 */
	public function isAJAX(): bool
	{
		return ( ! empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
		         strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
	}

	//--------------------------------------------------------------------

	/**
	 * Fetch an item from GET data.
	 *
	 * @param null $index  Index for item to fetch from $_GET.
	 * @param null $filter A filter name to apply.
	 *
	 * @return mixed
	 */
	public function getGet($index = null, $filter = null)
	{
		return $this->fetchGlobal(INPUT_GET, $index, $filter);
	}

	//--------------------------------------------------------------------

	/**
	 * Fetch an item from POST.
	 *
	 * @param null $index  Index for item to fetch from $_POST.
	 * @param null $filter A filter name to apply
	 *
	 * @return mixed
	 */
	public function getPost($index = null, $filter = null)
	{
		return $this->fetchGlobal(INPUT_POST, $index, $filter);
	}

	//--------------------------------------------------------------------

	/**
	 * Fetch an item from POST data with fallback to GET.
	 *
	 * @param null $index  Index for item to fetch from $_POST or $_GET
	 * @param null $filter A filter name to apply
	 *
	 * @return mixed
	 */
	public function getPostGet($index = null, $filter = null)
	{
		// Use $_POST directly here, since filter_has_var only
		// checks the initial POST data, not anything that might
		// have been added since.
		return isset($_POST[$index])
			? $this->getPost($index, $filter)
			: $this->getGet($index, $filter);
	}

	//--------------------------------------------------------------------

	/**
	 * Fetch an item from GET data with fallback to POST.
	 *
	 * @param null $index  Index for item to be fetched from $_GET or $_POST
	 * @param null $filter A filter name to apply
	 *
	 * @return mixed
	 */
	public function getGetPost($index = null, $filter = null)
	{
		// Use $_GET directly here, since filter_has_var only
		// checks the initial GET data, not anything that might
		// have been added since.
		return isset($_GET[$index])
			? $this->getGet($index, $filter)
			: $this->getPost($index, $filter);
	}

	//--------------------------------------------------------------------

	/**
	 * Fetch an item from the COOKIE array.
	 *
	 * @param null $index  Index for item to be fetched from $_COOKIE
	 * @param null $filter A filter name to be applied
	 *
	 * @return mixed
	 */
	public function getCookie($index = null, $filter = null)
	{
		return $this->fetchGlobal(INPUT_COOKIE, $index, $filter);
	}

	//--------------------------------------------------------------------

	/**
	 * Fetch the user agent string
	 *
	 * @param null $filter
	 */
	public function getUserAgent($filter = null)
	{
		return $this->fetchGlobal(INPUT_SERVER, 'HTTP_USER_AGENT', $filter);
	}

	//--------------------------------------------------------------------

	/**
	 * Set a cookie
	 *
	 * Accepts an arbitrary number of binds (up to 7) or an associateive
	 * array in the first parameter containing all the values.
	 *
	 * @param            $name      Cookie name or array containing binds
	 * @param string     $value     Cookie value
	 * @param string     $expire    Cookie expiration time in seconds
	 * @param string     $domain    Cookie domain (e.g.: '.yourdomain.com')
	 * @param string     $path      Cookie path (default: '/')
	 * @param string     $prefix    Cookie name prefix
	 * @param bool|false $secure    Whether to only transfer cookies via SSL
	 * @param bool|false $httponly  Whether only make the cookie accessible via HTTP (no javascript)
	 */
	public function setCookie(
		$name,
		$value = '',
		$expire = '',
		$domain = '',
		$path = '/',
		$prefix = '',
		$secure = false,
		$httponly = false
	)
	{
		if (is_array($name))
		{
			// always leave 'name' in last place, as the loop will break otherwise, due to $$item
			foreach (['value', 'expire', 'domain', 'path', 'prefix', 'secure', 'httponly', 'name'] as $item)
			{
				if (isset($name[$item]))
				{
					$item = $name[$item];
				}
			}
		}

		if ($prefix === '' && $this->cookiePrefix !== '')
		{
			$prefix = $this->cookiePrefix;
		}

		if ($domain == '' && $this->cookieDomain != '')
		{
			$domain = $this->cookieDomain;
		}

		if ($path === '/' && $this->cookiePath !== '/')
		{
			$path = $this->cookiePath;
		}

		if ($secure === false && $this->cookieSecure === true)
		{
			$secure = $this->cookieSecure;
		}

		if ($httponly === false && $this->cookieHTTPOnly !== false)
		{
			$httponly = $this->cookieHTTPOnly;
		}

		if ( ! is_numeric($expire))
		{
			$expire = time() - 86500;
		}
		else
		{
			$expire = ($expire > 0) ? time() + $expire : 0;
		}

		setcookie($prefix.$name, $value, $expire, $path, $domain, $secure, $httponly);
	}

	//--------------------------------------------------------------------

	/**
	 * Attempts to detect if the current connection is secure through
	 * a few different methods.
	 *
	 * @return bool
	 */
	public function isSecure(): bool
	{
		if ( ! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
		{
			return true;
		}
		elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
		{
			return true;
		}
		elseif ( ! empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off')
		{
			return true;
		}

		return false;
	}

	//--------------------------------------------------------------------

	/**
	 * Returns an array of all files that have been uploaded with this
	 * request. Each file is represented by an UploadedFile instance.
	 *
	 * @return array
	 */
	public function getFiles(): array
	{
		if (is_null($this->files))
		{
			// @todo modify to use the Services, at the very least.
			$this->files = new FileCollection();
		}

		return $this->files->all();
	}

	//--------------------------------------------------------------------

	/**
	 * Retrieves a single file by the name of the input field used
	 * to upload it.
	 *
	 * @param string $fileID
	 *
	 * @return UploadedFile|null
	 */
	public function getFile(string $fileID)
	{
		if (is_null($this->files))
		{
			// @todo modify to use the Services, at the very least.
			$this->files = new FileCollection();
		}

		if ( ! $this->files->hasFile($fileID))
		{
			return null;
		}

		return $this->files->getFile($fileID);
	}

	//--------------------------------------------------------------------

	/**
	 * Sets up our URI object based on the information we have. This is
	 * either provided by the user in the baseURL Config setting, or
	 * determined from the environment as needed.
	 *
	 * @param $protocol
	 * @param $baseURL
	 */
	protected function detectURI($protocol, $baseURL)
	{
		$this->uri->setPath($this->detectPath($protocol));

		// Based on our baseURL provided by the developer (if set)
		// set our current domain name, scheme
		if ( ! empty($baseURL))
		{
			$this->uri->setScheme(parse_url($baseURL, PHP_URL_SCHEME));
			$this->uri->setHost(parse_url($baseURL, PHP_URL_HOST));
			$this->uri->setPort(parse_url($baseURL, PHP_URL_PORT));
		}
		else
		{
			$this->isSecure() ? $this->uri->setScheme('https') : $this->uri->setScheme('http');

			// While both SERVER_NAME and HTTP_HOST are open to security issues,
			// if we have to choose, we will go with the getServer-controller version first.
			! empty($_SERVER['SERVER_NAME'])
				? (isset($_SERVER['SERVER_NAME']) ? $this->uri->setHost($_SERVER['SERVER_NAME']) : null)
				: (isset($_SERVER['HTTP_HOST']) ? $this->uri->setHost($_SERVER['HTTP_HOST']) : null);

			if ( ! empty($_SERVER['SERVER_PORT']))
			{
				$this->uri->setPort($_SERVER['SERVER_PORT']);
			}
		}
	}

	//--------------------------------------------------------------------

	/**
	 * Based on the URIProtocol Config setting, will attempt to
	 * detect the path portion of the current URI.
	 *
	 * @param $protocol
	 *
	 * @return string|string
	 */
	public function detectPath($protocol)
	{
		if (empty($protocol))
		{
			$protocol = 'REQUEST_URI';
		}

		switch ($protocol)
		{
			case 'REQUEST_URI':
				$path = $this->parseRequestURI();
				break;
			case 'QUERY_STRING':
				$path = $this->parseQueryString();
				break;
			case 'PATH_INFO':
			default:
				$path = isset($_SERVER[$protocol])
					? $_SERVER[$protocol]
					: $this->parseRequestURI();
				break;
		}

		return $path;
	}

	//--------------------------------------------------------------------

	/**
	 * Provides a convenient way to work with the Negotiate class
	 * for content negotiation.
	 *
	 * @param string $type
	 * @param array  $supported
	 * @param bool   $strictMatch
	 *
	 * @return mixed
	 */
	public function negotiate(string $type, array $supported, bool $strictMatch = false)
	{
		if (is_null($this->negotiate))
		{
			$this->negotiate = Services::negotiator($this, true);
		}

		switch (strtolower($type))
		{
			case 'media':
				return $this->negotiate->media($supported, $strictMatch);
				break;
			case 'charset':
				return $this->negotiate->charset($supported);
				break;
			case 'encoding':
				return $this->negotiate->encoding($supported);
				break;
			case 'language':
				return $this->negotiate->language($supported);
				break;
		}

		throw new \InvalidArgumentException($type.' is not a valid negotiation type.');
	}

	//--------------------------------------------------------------------

	/**
	 * Will parse the REQUEST_URI and automatically detect the URI from it,
	 * fixing the query string if necessary.
	 *
	 * @return string The URI it found.
	 */
	protected function parseRequestURI(): string
	{
		if ( ! isset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME']))
		{
			return '';
		}

		// parse_url() returns false if no host is present, but the path or query string
		// contains a colon followed by a number
		$parts = parse_url('http://dummy'.$_SERVER['REQUEST_URI']);
		$query = isset($parts['query']) ? $parts['query'] : '';
		$uri   = isset($parts['path']) ? $parts['path'] : '';

		if (isset($_SERVER['SCRIPT_NAME'][0]))
		{
			if (strpos($uri, $_SERVER['SCRIPT_NAME']) === 0)
			{
				$uri = (string)substr($uri, strlen($_SERVER['SCRIPT_NAME']));
			}
			elseif (strpos($uri, dirname($_SERVER['SCRIPT_NAME'])) === 0)
			{
				$uri = (string)substr($uri, strlen(dirname($_SERVER['SCRIPT_NAME'])));
			}
		}

		// This section ensures that even on servers that require the URI to be in the query string (Nginx) a correct
		// URI is found, and also fixes the QUERY_STRING getServer var and $_GET array.
		if (trim($uri, '/') === '' && strncmp($query, '/', 1) === 0)
		{
			$query                   = explode('?', $query, 2);
			$uri                     = $query[0];
			$_SERVER['QUERY_STRING'] = isset($query[1]) ? $query[1] : '';
		}
		else
		{
			$_SERVER['QUERY_STRING'] = $query;
		}

		parse_str($_SERVER['QUERY_STRING'], $_GET);

		if ($uri === '/' || $uri === '')
		{
			return '/';
		}

		return $this->removeRelativeDirectory($uri);
	}

	//--------------------------------------------------------------------

	/**
	 * Parse QUERY_STRING
	 *
	 * Will parse QUERY_STRING and automatically detect the URI from it.
	 *
	 * @return    string
	 */
	protected function parseQueryString(): string
	{
		$uri = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : @getenv('QUERY_STRING');

		if (trim($uri, '/') === '')
		{
			return '';
		}
		elseif (strncmp($uri, '/', 1) === 0)
		{
			$uri                     = explode('?', $uri, 2);
			$_SERVER['QUERY_STRING'] = isset($uri[1]) ? $uri[1] : '';
			$uri                     = $uri[0];
		}

		parse_str($_SERVER['QUERY_STRING'], $_GET);

		return $this->removeRelativeDirectory($uri);
	}

	//--------------------------------------------------------------------

	/**
	 * Remove relative directory (../) and multi slashes (///)
	 *
	 * Do some final cleaning of the URI and return it, currently only used in self::_parse_request_uri()
	 *
	 * @param    string $url
	 *
	 * @return    string
	 */
	protected function removeRelativeDirectory($uri)
	{
		$uris = [];
		$tok  = strtok($uri, '/');
		while ($tok !== false)
		{
			if (( ! empty($tok) || $tok === '0') && $tok !== '..')
			{
				$uris[] = $tok;
			}
			$tok = strtok('/');
		}

		return implode('/', $uris);
	}

	// --------------------------------------------------------------------
}
