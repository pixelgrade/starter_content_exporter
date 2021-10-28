var plugin = 'socket',
	source_SCSS = { admin: './scss/**/*.scss', public: './scss/**/*.scss'},
	dest_CSS = { admin:'./css/', public: './css/'},

	gulp 		= require('gulp'),
	sass 		= require('gulp-sass')(require('node-sass')),
	prefix 		= require('gulp-autoprefixer'),
	exec 		= require('gulp-exec'),
	del         = require('del');

// styles related
gulp.task('styles', function () {
	return gulp.src(source_SCSS.admin)
		.pipe( sass.sync( {'sourcemap': false, style: 'compact'} ).on('error', sass.logError) )
		.pipe( prefix() )
		.pipe(gulp.dest(dest_CSS.admin));
});

gulp.task('watch-styles', function () {
	return gulp.watch(source_SCSS.admin, ['styles-admin']);
});

// /**
//  * Create a zip archive out of the cleaned folder and delete the folder
//  */
// gulp.task( 'zip', ['build'], function() {
// 	return gulp.src( './' )
// 		.pipe( exec( 'cd ./../; rm -rf socket.zip; cd ./build/; zip -r -X ./../socket.zip ./socket; cd ./../; rm -rf build' ) );
//
// } );
//
// /**
//  * Copy theme folder outside in a build folder, recreate styles before that
//  */
// gulp.task( 'copy-folder', function() {
// 	return gulp.src( './' )
// 		.pipe( exec( 'rm -Rf ./../build; mkdir -p ./../build/socket; cp -Rf ./* ./../build/socket/' ) );
// } );
//
// /**
//  * Clean the folder of unneeded files and folders
//  */
// gulp.task( 'build', ['copy-folder'], function() {
//
// 	// files that should not be present in build zip
// 	files_to_remove = [
// 		'**/codekit-config.json',
// 		'node_modules',
// 		'tests',
// 		'.travis.yml',
// 		'circle.yml',
// 		'phpunit.xml.dist',
// 		'.sass-cache',
// 		'config.rb',
// 		'gulpfile.js',
// 		'package.json',
// 		'pxg.json',
// 		'build',
// 		'.idea',
// 		'**/*.css.map',
// 		'**/.git*',
// 		'*.sublime-project',
// 		'.DS_Store',
// 		'**/.DS_Store',
// 		'__MACOSX',
// 		'**/__MACOSX',
// 		'+development.rb',
// 		'+production.rb',
// 		'README.md'
// 	];
//
// 	files_to_remove.forEach( function( e, k ) {
// 		files_to_remove[k] = '../build/socket/' + e;
// 	} );
//
// 	del.sync(files_to_remove, {force: true});
// } );

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
var babel = require('babelify');

function compile_admin(done, watch) {
	var bundler = watchify(browserify('./src/socket.js', { debug: true }).transform(babel, {}));

	function rebundle_admin( done ) {
		return bundler.bundle()
			.on('error', function(err) { console.error(err); this.emit('end'); })
			.on('end', done)
			.pipe(source('socket.js'))
			.pipe(buffer())
			.pipe(sourcemaps.init({ loadMaps: true }))
			.pipe(sourcemaps.write('./'))
			.pipe(gulp.dest('./js'));
	}

	if (watch) {
		bundler.on('update', function() {
			console.log('-> bundling admin dashboard...' + new Date().getTime() / 1000);
			rebundle_admin( done );
		});
	}

	rebundle_admin( done );
}

function watch_admin() {
	return compile_admin(true);
}

gulp.task('compile', function(done) { return compile_admin(done, false); });

gulp.task('watch', function() { return watch_admin(true); });
