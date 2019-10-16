// Required for browser compatibility.
if (!global._babelPolyfill) {
	require('babel-polyfill');
}

import React from "react";
import ReactDOM from "react-dom";

// import PixelgradeCareNoSupportHere from './components/no_support.js';;
import SocketDashboard from './components/dashboard.js';

ReactDOM.render(<SocketDashboard />, document.getElementById('socket_dashboard')  );
