/**
 * omnimap's driver for Google Maps: draws the description of
 * Omnimap\Google\GoogleProvider::describe() with the Maps JavaScript API
 * (its bootstrap loader, importLibrary()): the map, its markers, its styled
 * map types and the style shown below each zoom. Register it with
 * omnimap.js: register('google', google).
 *
 * As glitchr/ux-google's maps did, the window hears 'tilesloaded', 'idle'
 * and 'drag' from the map.
 */

function bootstrap(library) {
  if (window.google && window.google.maps && window.google.maps.importLibrary) return;
  // Google's dynamic library import loader (developers.google.com/maps/documentation/javascript/load-maps-js-api), unminified.
  const maps = ((window.google = window.google || {}).maps = window.google.maps || {});
  const libraries = new Set();
  const parameters = new URLSearchParams();
  let promise;
  const load = () => promise || (promise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    parameters.set('libraries', [...libraries].join(','));
    Object.entries(library).forEach(([key, value]) => parameters.set(key.replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`), value));
    parameters.set('callback', 'google.maps.__ib__');
    script.src = `https://maps.googleapis.com/maps/api/js?${parameters}`;
    maps.__ib__ = resolve;
    script.onerror = () => { promise = null; reject(new Error('The Google Maps JavaScript API could not load.')); };
    const nonce = document.querySelector('script[nonce]');
    script.nonce = (nonce && nonce.nonce) || '';
    document.head.append(script);
  }));
  maps.importLibrary = (name, ...rest) => { libraries.add(name); return load().then(() => maps.importLibrary(name, ...rest)); };
}

function styleSwitcher(map, zoomStyles) {
  const below = Object.entries(zoomStyles.below || {}).map(([zoom, name]) => [Number(zoom), name]).sort((a, b) => a[0] - b[0]);
  return () => {
    const zoom = map.getZoom();
    const match = below.find(([stop]) => zoom < stop);
    map.setMapTypeId(match ? match[1] : zoomStyles.default);
  };
}

export async function draw(element, description) {
  bootstrap(description.library || {});
  const view = description.view;
  const options = description.options || {};
  const { Map, StyledMapType } = await window.google.maps.importLibrary('maps');
  const { Marker } = await window.google.maps.importLibrary('marker');

  const map = new Map(element, {
    center: view.center,
    zoom: view.zoom,
    disableDefaultUI: !view.controls,
    ...(options.map || {}),
  });
  ['tilesloaded', 'idle', 'drag'].forEach((event) => map.addListener(event, () => window.dispatchEvent(new Event(event))));

  (view.markers || []).forEach((marker) => new Marker({ position: marker.position, title: marker.title || undefined, map }));

  (options.styles || []).forEach((style) => {
    map.mapTypes.set(style.name, new StyledMapType(style.rules || [], { name: style.name, baseMapTypeId: style.baseMapTypeId || 'roadmap' }));
  });
  if (options.zoomStyles && options.zoomStyles.default) {
    const apply = styleSwitcher(map, options.zoomStyles);
    if (Object.keys(options.zoomStyles.below || {}).length) {
      map.addListener('idle', apply);
      map.addListener('zoom_changed', apply);
    }
    apply();
  }

  return map;
}

export default { draw };
