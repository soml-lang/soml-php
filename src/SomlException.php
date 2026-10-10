<?php

declare(strict_types=1);

namespace Soml;

/**
Implemented by `DecodeException` and `EncodeException`, to catch either one.

```
try {
	$config = Soml::decodeFile('config.soml');
	file_put_contents('config.canonical.soml', Soml::encode($config, canonical: true));
} catch (SomlException $exception) {
	echo $exception->getMessage();
}
```
*/
interface SomlException extends \Throwable {}
