/**
 * Room numbers typed as a list: «101, 102, 201» or a series with a dash, «101-110»,
 * also with a prefix («A1-A5») or leading zeros («01-12»). Anything that is not a
 * series is kept as it is; the server checks codes already used.
 */
export const MAX_ROOMS_AT_ONCE = 100;

const SERIES = /^([A-Za-z]*)(\d+)[-–]\1?(\d+)$/;

export function expandCodes( text ) {
	const out = [];
	for ( const part of String( text || '' )
		.replace( /\s*[-–]\s*/g, '-' )
		.split( /[,;\s]+/ ) ) {
		const item = part.trim();
		if ( ! item ) {
			continue;
		}
		const m = item.match( SERIES );
		if ( ! m ) {
			out.push( item.toUpperCase() );
			continue;
		}
		const from = parseInt( m[ 2 ], 10 );
		const to = parseInt( m[ 3 ], 10 );
		const width = m[ 2 ].startsWith( '0' ) ? m[ 2 ].length : 0;
		const step = from <= to ? 1 : -1;
		// A series too long would only produce an error: stop just past the limit.
		for ( let n = from; step > 0 ? n <= to : n >= to; n += step ) {
			out.push(
				m[ 1 ].toUpperCase() + String( n ).padStart( width, '0' )
			);
			if ( out.length > MAX_ROOMS_AT_ONCE ) {
				return out;
			}
		}
	}
	return out;
}
