// Required for browser compatibility.
if (!global._babelPolyfill) {
	require('babel-polyfill');
}
import 'whatwg-fetch';

// Needed for onTouchTap
// http://stackoverflow.com/a/34015469/988941
import injectTapEventPlugin from 'react-tap-event-plugin';
injectTapEventPlugin();

import React from "react";
import ReactDOM from "react-dom";

// import PixelgradeCareNoSupportHere from './components/no_support.js';;
import SocketDashboard from './components/dashboard.js';

ReactDOM.render(<SocketDashboard />, document.getElementById('socket_dashboard')  );