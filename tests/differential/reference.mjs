// Reads a JSON array of base64 documents, and writes what the JavaScript reference gives for each: the canonical form or the error.
import {readFileSync, writeFileSync} from 'node:fs';
import {parse, stringify} from '../../../soml-javascript/source/index.ts';

const [input, output] = process.argv.slice(2);
const results = [];

for (const encoded of JSON.parse(readFileSync(input, 'utf8'))) {
	const bytes = Buffer.from(encoded, 'base64');

	try {
		results.push(JSON.stringify({canonical: stringify(parse(new Uint8Array(bytes)), {canonical: true})}));
	} catch (error) {
		if (error.name !== 'ParseError') {
			results.push(JSON.stringify({crash: String(error)}));
			continue;
		}

		results.push(JSON.stringify({reason: error.reason, line: error.line, column: error.column}));
	}
}

writeFileSync(output, `[${results.join(',')}]`);
