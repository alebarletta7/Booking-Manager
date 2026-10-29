/**
 * The property the user is working on: chosen in the top bar, remembered on the server
 * (/me/preferences), shared by every screen.
 */
import { createContext, useContext } from '@wordpress/element';

export const PropertyContext = createContext( {
	property: null,
	properties: [],
	select: () => {},
	refreshMe: () => {},
} );

export function useProperty() {
	return useContext( PropertyContext );
}
