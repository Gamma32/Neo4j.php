<?php 

class IndexService {

	var $_neo_db;
	var $_uri;
	var $_data;
	
	public function __construct( GraphDatabaseService $neo_db)
	{
		$this->_neo_db = $neo_db;
	}
	
	public function index( Node $node, $key, $value ) {
		
		$this->_uri = $this->_neo_db->getBaseUri().'index/node/'.$key.'/'.$value;
		$this->_data = $node->getUri();

		list($response, $http_code) = HTTPUtil::request($this->_uri, HTTPUtil::POST, $this->_data );	
		if ($http_code!=201) throw new HttpException($http_code);
		
	}
	
	public function removeIndex(Node $node, $key, $value)
	{
		$this->_uri = $this->_neo_db->getBaseUri().'index/node/'.$key.'/'.$value.'/'.$node->getId();
		list($response, $http_code) = HTTPUtil::deleteRequest($this->_uri);
		if ($http_code!=204) throw new HttpException($http_code);
	}

	public function getNodes($key, $value ) {
		
		$this->_uri = $this->_neo_db->getBaseUri().'index/node/'.$key.'/'.$value;
		
		list($response, $http_code) = HTTPUtil::jsonGetRequest($this->_uri);
		if ($http_code!=200) throw new HttpException("http code: " . $http_code . ", response: " . print_r($response, true));
		$nodes = array();
		foreach($response as $nodeData) {
			$nodes[] = Node::inflateFromResponse( $this->_neo_db, $nodeData );
		}
		
		if (empty($nodes)) throw new NotFoundException();
		
		return $nodes;
		
	}
	
	// A hack for now.  The REST API doesn't offer an implementation of 
	// org.neo4j.index.IndexServe.getSingleNode();
	// So we just get the first element in the returned array.
	public function getNode($key, $value) {
		
		$nodes = $this->getNodes($key, $value);
				
		return $nodes[0];
		
	}
	
}
