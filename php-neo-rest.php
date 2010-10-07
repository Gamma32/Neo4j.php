<?php

//namespace NeoRest;


class Node extends PropertyContainer
{
	var $_neo_db;
	var $_id;
	var $_is_new;
	var $_pathFinderData;
	
	public function __construct($neo_db)
	{
		$this->_neo_db = $neo_db;
		$this->_is_new = TRUE;
	}
	
	public function delete()
	{
		if (!$this->_is_new) 
		{
			list($response, $http_code) = HTTPUtil::deleteRequest($this->getUri());
			
			if ($http_code!=204) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
			
			$this->_id = NULL;
			$this->_id_new = TRUE;
		}
	}
	
	public function save()
	{
		if ($this->_is_new) {
			list($response, $http_code) = HTTPUtil::jsonPostRequest($this->getUri(), $this->_data);
			if ($http_code!=201) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
		} else {
			list($response, $http_code) = HTTPUtil::jsonPutRequest($this->getUri().'/properties', $this->_data);
			if ($http_code!=204) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
		}

		if ($this->_is_new) 
		{
			$this->_id = end(explode("/", $response['self']));
			$this->_is_new=FALSE;
		}
	}
	
	public function getId()
	{
		return $this->_id;
	}
	
	public function isSaved()
	{
		return !$this->_is_new;
	}
	
	public function getRelationships($direction=Relationship::DIRECTION_BOTH, $types=NULL)
	{
		$uri = $this->getUri().'/relationships';
		
		switch($direction)
		{
			case Relationship::DIRECTION_IN:
				$uri .= '/' . DIRECTION::INCOMING;
				break;
			case Relationship::DIRECTION_OUT:
				$uri .= '/' . DIRECTION::OUTGOING;
				break;
			default:
				$uri .= '/' . DIRECTION::BOTH;
		}
		
		if ($types)
		{
			if (is_array($types)) $types = implode("&", $types);
			
			$uri .= '/'.$types;
		}
		
		list($response, $http_code) = HTTPUtil::jsonGetRequest($uri);
		
		$relationships = array();
		
		foreach($response as $result)
		{
			$relationships[] = Relationship::inflateFromResponse($this->_neo_db, $result);
		}
		
		return $relationships;
	}
	
	public function createRelationshipTo($node, $type)
	{
		$relationship = new Relationship($this->_neo_db, $this, $node, $type);
		return $relationship;
	}
	
	public function getUri()
	{
		$uri = $this->_neo_db->getBaseUri().'node';
	
		if (!$this->_is_new) $uri .= '/'.$this->getId();
	
		return $uri;
	}
	
	public static function inflateFromResponse($neo_db, $response)
	{
		$node = new Node($neo_db);
		$node->_is_new = FALSE;
		$node->_id = end(explode("/", $response['self']));
		$node->setProperties($response['data']);

		return $node;
	}

// curl -H Accept:application/json -H Content-Type:application/json -d
// '{ "to": "http://localhost:9999/node/3" }'
// -X POST http://localhost:9999/node/1/pathfinder
// TODO Add handling for relationships
// TODO Add algorithm parameter
	public function findPaths(Node $toNode, $maxDepth=null, RelationshipDescription $relationships=null, $singlePath=null)
	{
		
		$this->_pathFinderData['to'] =  $this->_neo_db->getBaseUri().'node'.'/'.$toNode->getId();
		if ($maxDepth) $this->_pathFinderData['max depth'] = $maxDepth;
		if ($singlePath) $this->_pathFinderData['single path'] = $singlePath;
		if ($relationships) $this->_pathFinderData['relationships'] = $relationships->get();
		
		list($response, $http_code) = HTTPUtil::jsonPostRequest($this->getUri().'/pathfinder', $this->_pathFinderData);
		
		if ($http_code==404) throw new NotFoundException;
		if ($http_code!=200) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
		
		$paths = array();
		foreach($response as $result)
		{
				$paths[] = Path::inflateFromResponse($this->_neo_db, $result);	
		}
		
		if (empty($paths)) {
			throw new NotFoundException();
		}
		
		return $paths;
	}	

	// Convenience method just returns the first path
	public function findPath(Node $toNode, $maxDepth=null, RelationshipDescription $relationships=null)
	{
		$paths = $this->findPaths($toNode, $maxDepth, $relationships, 'true');
		return $paths[0];
	}
	
	
}

class Relationship extends PropertyContainer
{
	const DIRECTION_BOTH 	= 'BOTH';
	const DIRECTION_IN 		= 'IN';
	const DIRECTION_OUT 	= 'OUT';
	
	var $_is_new;
	var $_neo_db;
	var $_id;
	var $_type;
	var $_node1;
	var $_node2;
	
	public function __construct($neo_db, $start_node, $end_node, $type)
	{
		$this->_neo_db = $neo_db;
		$this->_is_new = TRUE;
		$this->_type = $type;
		$this->_node1 = $start_node;
		$this->_node2 = $end_node;
	}
	
	public function getId()
	{
		return $this->_id;
	}
	
	public function isSaved()
	{
		return !$this->_is_new;
	}
	
	public function getType()
	{
		return $this->_type;		
	}
	
	public function isType($type)
	{
		return $this->_type==$type;
	}
	
	public function getStartNode()
	{
		return $this->_node1;
	}
	
	public function getEndNode()
	{
		return $this->_node2;
	}
	
	public function getOtherNode($node)
	{
		return ($this->_node1->getId()==$node->getId()) ? $this->getStartNode() : $this->getEndNode();
	}
	
	public function save()
	{
		if ($this->_is_new) {
			$payload = array(
				'to' => $this->getEndNode()->getUri(),
				'type' => $this->_type,
				'data'=>$this->_data
			);
			
			list($response, $http_code) = HTTPUtil::jsonPostRequest($this->getUri(), $payload);
			
			if ($http_code!=201) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
		} else {
			list($response, $http_code) = HTTPUtil::jsonPutRequest($this->getUri().'/properties', $this->_data);
			if ($http_code!=204) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
		}
				
		if ($this->_is_new) 
		{
			$this->_id = end(explode("/", $response['self']));
			$this->_is_new=FALSE;
		}
	}
	
	public function delete()
	{
		if (!$this->_is_new) 
		{
			list($response, $http_code) = HTTPUtil::deleteRequest($this->getUri());

			if ($http_code!=204) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
			
			$this->_id = NULL;
			$this->_id_new = TRUE;
		}
	}
	
	public function getUri()
	{
		if ($this->_is_new)
			$uri = $this->getStartNode()->getUri().'/relationships';
		else
			$uri  = $this->_neo_db->getBaseUri().'relationship/'.$this->getId();
	
		//if (!$this->_is_new) $uri .= '/'.$this->getId();
	
		return $uri;
	}
	
	public static function inflateFromResponse($neo_db, $response)
	{
		$start_id = end(explode("/", $response['start']));
		$end_id = end(explode("/", $response['end']));

		$start = $neo_db->getNodeById($start_id);
		$end = $neo_db->getNodeById($end_id);
		
		$relationship = new Relationship($neo_db, $start, $end, $response['type']);
		$relationship->_is_new = FALSE;
		$relationship->_id = end(explode("/", $response['self']));
		$relationship->setProperties($response['data']);
		
		return $relationship;
	}
}

/**
 *	Very messy HTTP utility library
 */
class HTTPUtil 
{
	const GET = 'GET';
	const POST = 'POST';
	const PUT = 'PUT';
	const DELETE = 'DELETE';
	
	/**
	 *	A general purpose HTTP request method
	 */
	function request($url, $method='GET', $post_data='', $content_type='', $accept_type='')
	{
		// Uncomment for debugging
		//echo 'HTTP: ', $method, " : " ,$url , " : ", $post_data, "\n";
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE); 
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION,1);

		//if ($method==self::POST){
		//	curl_setopt($ch, CURLOPT_POST, true); 
		//} else {
		//	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		//}
		
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	
		if ($post_data)
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
			
			$headers = array(
						'Content-Length: ' . strlen($post_data),
						'Content-Type: '.$content_type,
						'Accept: '.$accept_type
						);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
		}

		// Batch jobs are overloading the local server so try twice, with a pause in the middle
		// TODO There must be a better way of handling this. What I've got below is an ugly hack.
		$count = 6;
		do {
			$count--;
			$response = curl_exec($ch);
			$error = curl_error($ch);
			if ($error != '') {
				echo "Curl got an error, sleeping for a moment before retrying: $count\n";
				sleep(10);
				$founderror = true;
			} else {
				$founderror = false;
			}
			
		} while ($count && $founderror);
	
		if ($error != '') {
			throw new CurlException($error);
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		return array($response, $http_code);
	}
	
	/**
	 *	A HTTP request that returns json and optionally sends a json payload (post only)
	 */
	function jsonRequest($url, $method, $data=NULL)
	{
		$json = json_encode($data);
// print_r($json);		
		$ret = self::request($url, $method, $json, 'application/json', 'application/json');
		$ret[0] = json_decode($ret[0], TRUE);
		return $ret;
	}
	
	function jsonPutRequest($url, $data)
	{
		return self::jsonRequest($url, self::PUT, $data);
	}
	
	function jsonPostRequest($url, $data)
	{
		return self::jsonRequest($url, self::POST, $data);
	}
	
	function jsonGetRequest($url)
	{
		return self::jsonRequest($url, self::GET);
	}
	
	function deleteRequest($url)
	{
		return self::request($url, self::DELETE);
	}
}














