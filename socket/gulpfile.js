var plugin = 'socket',
	source_SCSS = { admin: './scss/**/*.scss', public: './scss/**/*.scss'},
	dest_CSS = { admin:'./css/', public: './css/'},

	gulp 		= require('gulp'),
	sass 		= require('gulp-sass'),
	prefix 		= require('gulp-autoprefixer'),
	exec 		= require('gulp-exec'),
	replace 	= require('gulp-replace'),
	minify 		= require('gulp-minify-css'),
	concat 		= require('gulp-concat'),
	notify 		= require('gulp-notify'),
	beautify 	= require('gulp-beautify'),
	csscomb 	= require('gulp-csscomb'),
	cmq 		= require('gulp-combine-media-queries'),
	chmod 		= require('gulp-chmod'),
	fs          = require('fs'),
	rtlcss 		= require('rtlcss'),
	postcss 	= require('gulp-postcss'),
	del         = require('del'),
	rename 		= require('gulp-rename');

require('es6-promise').polyfill();


var options = {
	silent: true,
	continueOnError: true // default: false
};

// styles related
gulp.task('styles-admin', function () {
	return gulp.src(source_SCSS.admin)
		.pipe(sass({'sourcemap': false, style: 'compact'}))
			.on('error', function (e) {
				console.log(e.message);
			})
		.pipe(prefix("last 1 version", "> 1%", "ie 8", "ie 7"))
		.pipe(gulp.dest(dest_CSS.admin));
});

gulp.task('styles-public', function () {
	return gulp.src(source_SCSS.public)
		.pipe(sass({'sourcemap': false, style: 'compact'}))
		.on('error', function (e) {
			console.log(e.message);
		})
		.pipe(prefix("last 1 version", "> 1%", "ie 8", "ie 7"))
		.pipe(gulp.dest(dest_CSS.public));
});

gulp.task('styles', ['styles-admin', 'styles-public'], function () {
	// ok
});

gulp.task('watch-styles', function () {
	return gulp.watch(source_SCSS.admin, ['styles-admin']);
});

gulp.task('watch-public', function () {
	return gulp.watch(source_SCSS.public, ['styles-public']);
});

/**
 * Create a zip archive out of the cleaned folder and delete the folder
 */
gulp.task( 'zip', ['build'], function() {
	return gulp.src( './' )
		.pipe( exec( 'cd ./../; rm -rf socket.zip; cd ./build/; zip -r -X ./../socket.zip ./socket; cd ./../; rm -rf build' ) );

} );

/**
 * Copy theme folder outside in a build folder, recreate styles before that
 */
gulp.task( 'copy-folder', function() {
	return gulp.src( './' )
		.pipe( exec( 'rm -Rf ./../build; mkdir -p ./../build/socket; cp -Rf ./* ./../build/socket/' ) );
} );

/**
 * Clean the folder of unneeded files and folders
 */
gulp.task( 'build', ['copy-folder'], function() {

	// files that should not be present in build zip
	files_to_remove = [
		'**/codekit-config.json',
		'node_modules',
		'tests',
		'.travis.yml',
		'circle.yml',
		'phpunit.xml.dist',
		'.sass-cache',
		'config.rb',
		'gulpfile.js',
		'package.json',
		'pxg.json',
		'build',
		'.idea',
		'**/*.css.map',
		'**/.git*',
		'*.sublime-project',
		'.DS_Store',
		'**/.DS_Store',
		'__MACOSX',
		'**/__MACOSX',
		'+development.rb',
		'+production.rb',
		'README.md'
	];

	files_to_remove.forEach( function( e, k ) {
		files_to_remove[k] = '../build/socket/' + e;
	} );

	del.sync(files_to_remove, {force: true});
} );

// usually there is a default task  for lazy people who just wanna type gulp
gulp.task('default', ['styles'], function () {
	// silence
});

gulp.task('susy', function() {
	return gulp.src('scss/*.scss')
		.pipe(sass({
			// outputStyle: 'compressed',
			includePaths: ['node_modules/susy/sass']
		}).on('error', sass.logError))
		.pipe(gulp.dest('css'));
});

var sourcemaps = require('gulp-sourcemaps');
var source = require('vinyl-source-stream');
var buffer = require('vinyl-buffer');
var browserify = require('browserify');
var watchify = require('watchify');
var react = require('react');
var reactdom = require('react-dom');
var babel = require('babelify');

function compile_admin(watch) {
	var bundler = watchify(browserify('./src/socket.js', { debug: true }).transform(babel, { presets: ["es2015", "stage-0", "react"]}));

	function rebundle_admin() {
		bundler.bundle()
			.on('error', function(err) { console.error(err); this.emit('end'); })
			.pipe(source('socket.js'))
			.pipe(buffer())
			.pipe(sourcemaps.init({ loadMaps: true }))
			.pipe(sourcemaps.write('./'))
			.pipe(gulp.dest('./js'));
	}

	if (watch) {
		bundler.on('update', function() {
			console.log('-> bundling admin dashboard...' + new Date().getTime() / 1000);
			rebundle_admin();
		});
	}
	rebundle_admin();
}

function watch_admin() {
	return compile_admin(true);
};

gulp.task('react', function() { return compile(); });
gulp.task('compile', function() { return compile_admin(false); });

gulp.task('watch', ['watch-styles'], function() { return watch_admin(true); });


gulp.task('default', ['watch-all']);

/**
 * Short commands help
 */
gulp.task('help', function () {

	var $help = '\nCommands available : \n \n' +
		'=== General Commands === \n' +
		'start              (default)Compiles all styles and scripts and makes the theme ready to start \n' +
		'zip                Generate the zip archive \n' +
		'build              Generate the build directory with the cleaned theme \n' +
		'help               Print all commands \n' +
		'=== Style === \n' +
		'styles             Compiles styles in production mode\n' +
		'styles-dev         Compiles styles in development mode \n' +
		'styles-admin       Compiles admin styles \n' +
		'=== Scripts === \n' +
		'scripts            Concatenate all js scripts \n' +
		'scripts-dev        Concatenate all js scripts \n' +
		'=== Watchers === \n' +
		'watch              Watches all js and scss files \n' +
		'styles-watch       Watch only styles\n' +
		'scripts-watch      Watch scripts only \n';

	console.log($help);

});
