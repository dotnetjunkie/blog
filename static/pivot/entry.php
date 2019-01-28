<?php
	$blogPath = 'https://blogs.cuttingedge.it/steven/posts/';

	$redirects = [
		// Migrated posts
		101 => "2017/simple-injector-v4/",
		100 => "2016/abstract-factories-are-a-code-smell/",
		 99 => "2015/code-smell-injecting-runtime-data-into-components/",
		 98 => "2014/dependency-injection-in-attributes-dont-do-it/",
		 97 => "2013/di-anti-pattern-multiple-constructors/",
		 96 => "2013/simple-injector-2-the-future-is-here/",
		 95 => "2012/writing-highly-maintainable-wcf-services/",
		 94 => "2012/primitive-dependencies-with-simple-injector/",
		 93 => "2012/returning-data-from-command-handlers/",
		 92 => "2011/meanwhile-on-the-query-side-of-my-architecture/",
		 91 => "2011/meanwhile-on-the-command-side-of-my-architecture/",
		 90 => "2011/adding-covariance-and-contravariance-to-simple-injector/",
		 81 => "2010/di-in-asp-net-web-forms/",
		 76 => "2010/breaking-changes-in-smtpclient-in-net40/",
		 75 => "2010/protecting-against-regex-dos-attacks/",
		 48 => "2009/protecting-against-xml-expansion-attacks/",
		 42 => "2008/the-death-of-linq-to-sql/",
		 29 => "2007/readonlydictionary/",
		  4 => "2006/net-backwards-compatibility/",
		  3 => "2006/welcome-to-my-blog/",
	];
	
	$id = (int)$_REQUEST['id'];
	
	$redirect = $redirects[$id];
	
	if (isset($redirect))
	{		
		$url = $blogPath . $redirect;
		
		// Write redirect for migrated post
		http_response_code(301); // permanent redirect
		header("Location: " . $url);
		
?><!DOCTYPE html>
<html>
<head>
	<title><?php echo $url ?></title>
	<link rel="canonical" href="<?php echo $url ?>"/>
	<meta name="robots" content="noindex">
	<meta charset="utf-8" />
	<meta http-equiv="refresh" content="0; url=<?php echo $url ?>" />
</head>
<body>
</body>
</html><?php
	}
	else if ($id > 0 && $id < 100)
	{
		// This is a post that hasn't been migrated to MD,
		// so instead we load a copy of old blog post.
		echo file_get_contents("entries/" . $id . ".html");
	}	
	else
	{
		// There is no such post
		http_response_code(404);
		
?><!DOCTYPE html>
<html>
<head>
	<title>Entry Does not Exist!</title>
	<meta name="robots" content="noindex">
	<meta charset="utf-8" />
</head>
<body>
	<h1>Entry Does not Exist!</h1>
</body>
</html><?php			
	}
?>