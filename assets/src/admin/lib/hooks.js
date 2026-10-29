/** Loading data with state: { data, error, loading, reload }. */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { readableError } from './api';

export function useLoad( loader, deps = [] ) {
	const [ state, setState ] = useState( {
		data: null,
		error: null,
		loading: true,
	} );
	const alive = useRef( true );
	const run = useCallback( () => {
		setState( ( s ) => ( { ...s, loading: true, error: null } ) );
		return Promise.resolve()
			.then( loader )
			.then(
				( data ) =>
					alive.current &&
					setState( { data, error: null, loading: false } ),
				( error ) =>
					alive.current &&
					setState( {
						data: null,
						error: readableError( error ),
						loading: false,
					} )
			);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, deps );
	useEffect( () => {
		alive.current = true;
		run();
		return () => {
			alive.current = false;
		};
	}, [ run ] );
	return {
		...state,
		reload: run,
		setData: ( data ) => setState( ( s ) => ( { ...s, data } ) ),
	};
}

/**
 * A write with its pending state and error: run(fn) returns the result or null.
 */
export function useAction() {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const run = useCallback( async ( fn ) => {
		setBusy( true );
		setError( null );
		try {
			return await fn();
		} catch ( e ) {
			setError( readableError( e ) );
			return null;
		} finally {
			setBusy( false );
		}
	}, [] );
	return { busy, error, setError, run };
}
