// Reads a JSON array of float bit patterns as hexadecimal, and writes the canonical spelling of each, from the JavaScript reference.
import {readFileSync, writeFileSync} from 'node:fs';
import {formatFloat} from '../../../soml-javascript/source/index.ts';

const [input, output] = process.argv.slice(2);
const view = new DataView(new ArrayBuffer(8));
const results = JSON.parse(readFileSync(input, 'utf8')).map(hex => {
	view.setBigUint64(0, BigInt(`0x${hex}`));
	return formatFloat(view.getFloat64(0));
});

writeFileSync(output, JSON.stringify(results));
