import React from "react"
import PropTypes from 'prop-types'

import {
	Button,
	Form,
	Header,
	Icon,
	Image,
	Popup,
	Placeholder,
	Segment,
	Grid
} from 'semantic-ui-react'
import isEmpty from 'lodash/isEmpty'
import isUndefined from 'lodash/isUndefined'

class SocketGallery extends React.Component {

	constructor(props) {
		// this makes the this
		super(props);

		this.frame = null;

		// get the current state localized by wordpress
		this.state = {
			loading: true,
			value_on_open: null,
			attachments: []
		};

		if ( isEmpty( this.props.value ) ) {
			this.state.value = []
		} else {
			this.state.value = this.props.value.split(',').map(function(t){return parseInt(t)})
		}

		this.handleOpen = this.handleOpen.bind(this);
		this.initMediaModal = this.initMediaModal.bind(this);
		this.onclose = this.onclose.bind(this);
		this.getSelection = this.getSelection.bind(this);
		this.clearGallery = this.clearGallery.bind(this);

		this.initMediaModal();
	}

	render() {
		let component = this,
			output = null,
			value = this.state.value,
			placeholder = this.props.placeholder || 'Select';

		const square = {
			width: 150,
			height: 150,
			border: '1px solid #333',
			padding: 0,
			margin: '15px 15px 35px 15px'
		}

		let ids = value

		output =
			<div>
				<Form.Field className="gallery">
					<Grid onClick={component.handleOpen} style={{minHeight: 120, padding: '15px'}}>
						{isEmpty(ids)
							?
							<Grid.Column>
								<Image disabled src='https://react.semantic-ui.com/images/wireframe/white-image.png' size='small' />
							</Grid.Column>
							:
							''
						}
						{Object.keys(ids).map(function ( i ) {
							let id = Number(ids[i]),
								attachment = wp.media.model.Attachment.get( id ),
								url = '';

							if ( typeof  attachment.attributes.sizes === "undefined" ) {
								return <Grid.Column key={id} style={square}>
										<Placeholder fluid>
											<Placeholder.Image square />
										</Placeholder>
									</Grid.Column>
							}

							if ( isUndefined( attachment.attributes.sizes.thumbnail ) ) {
								url = attachment.attributes.sizes.full.url;
							} else {
								url = attachment.attributes.sizes.thumbnail.url;
							}

							if ( typeof attachment.attributes.sizes !== "undefined" ) {
								return <Grid.Column
									key={id}
									style={square}
									color="grey"
								>
									<Image src={url} size='small' width="150" centered/>
									<Header as='h4' style={{ position: 'absolute', bottom: '-25px' }}>{attachment.attributes.title}</Header>
								</Grid.Column>
							}
						})}
					</Grid>
				</Form.Field>

				<Popup
					trigger={<Button basic circular style={ { top: '0', position: 'absolute', right: '0', height: '32px', margin: '9px', padding: '6px', textIndent: '2px' } } onClick={this.clearGallery}>
						<Button.Content visible>
							<Icon name='close' />
						</Button.Content>
					</Button>}
					content='Click here if you want to remove all media'
				/>
			</div>
		return output;
	}

	clearGallery(e){
		e.preventDefault();

		let component = this,
			name = component.props.name;

		component.props.setupLoadingFlag( true );

		component.setState({ value: []});

		setTimeout(function () {
			jQuery.ajax({
				url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
				method: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
				},
				data: {
					'socket_nonce': socket.wp_rest.socket_nonce,
					name: name,
					value: component.state.value.join(',')
				}
			}).done(function (response) {
				component.props.setupLoadingFlag( false );
			}).error(function (err) {
				console.log(err);
				component.props.setupLoadingFlag( false );
			});
		}, 1000 );
	}

	handleOpen = (e) => {
		let component = this,
			name = component.props.name;

		component.state.value_on_open = component.state.value;
		e.preventDefault();
		component.frame = wp.media.socketgallery[component.props.name].frame();
		component.frame.open();

		if ( typeof component.frame.socketbound === "undefined" ) {
			component.frame.on('close', function () {
				component.onclose( name );
				component.frame = null;
			});
			component.frame.socketbound = true;
		}
	}

	onclose( name ){
		let component = this

		component.props.setupLoadingFlag( true )

		setTimeout(function () {
			jQuery.ajax({
				url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
				method: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
				},
				data: {
					'socket_nonce': socket.wp_rest.socket_nonce,
					name: name,
					value: component.state.value.join(',')
				}
			}).done(function (response) {
				component.props.setupLoadingFlag( false );
			}).error(function (err) {
				console.log(err);
				component.props.setupLoadingFlag( false );
			});
		}, 1000 );
	}

	componentWillMount() {
		let component = this;

		if ( isEmpty( component.state.attachments ) && ! isEmpty( component.state.value ) ) {
			var attachments = [];
			var res = component.getSelection( component.state.value.join(',') );
			// },500);
			// just wait a sec
			// setTimeout(function () {
			// 	{Object.keys(component.state.value).map(function ( i ) {
			// 		attachments.push( wp.media.model.Attachment.get( component.state.value[i] ) )
			// 	})}
			// component.setState( { attachments: res.models } );
		}
	}

	initMediaModal() {
		let component = this;

		wp.media.socketgallery[component.props.name] = {
			frame: function () {
				if (this._frame) return this._frame;

				let selection = this.select();

				this._frame = wp.media({
					className: 'media-frame no-sidebar',
					id: 'socket-gallery',
					frame: 'post',
					title: 'Select Your Images',
					button: {
						text: 'Choose'
					},
					state: 'gallery-edit',
					editing: true,
					multiple: true,
					library: {
						type: 'image'
					},
					selection: selection
				})

				this._frame.on('ready', this.ready);
				this._frame.on('open', this.open);
				this._frame.on('update', this.update);

				return this._frame;
			},

			ready: function () {
				jQuery('.media-modal').addClass('no-sidebar smaller');
			},

			open: function () {
				console.log(' open ');
			},

			close: function ( cb ) {
				cb();
				wp.media.socketgallery = [];
			},

			update: function () {
				let settings = wp.media.view.settings,
					controller = wp.media.socketgallery[component.props.name]._frame.states.get('gallery-edit'),
					library = controller.get('library'),
					$return = [],
					ids = library.pluck('id');

				component.setState({value: ids}, function () {
					controller.reset();
				});
			},

			// Gets initial gallery-edit images. Function modified from wp.media.gallery.edit
			// in wp-includes/js/media-editor.js.source.html
			select: function () {
				let shortcode = wp.shortcode.next('gallery', '[gallery ids="1"'),
					attachments, selection;

				if ( ! isEmpty( component.state.value ) ) {
					shortcode = wp.shortcode.next('gallery', '[gallery ids="' + component.state.value + '"]')
				}

				// Bail if we didn't match the shortcode or all of the content.
				if (!shortcode) return;

				// Ignore the rest of the match object.
				shortcode = shortcode.shortcode;

				attachments = wp.media.gallery.attachments(shortcode);
				selection = new wp.media.model.Selection(attachments.models, {
					props: attachments.props.toJSON(),
					multiple: true
				});

				selection.gallery = attachments.gallery;

				// Fetch the query's attachments, and then break ties from the
				// query to allow for sorting.
				selection.more().done(function () {
					// Break ties with the query.
					selection.props.set({ query: false });
					selection.unmirror();
					selection.props.unset('orderby');
				});

				return selection;
			},
		};
	}

	getSelection ( idsString ) {
		let component = this,
			shortcode = wp.shortcode.next('gallery', '[gallery ids="1"]'),
			attachments, selection;

		if ( ! isEmpty( component.state.value ) ) {
			shortcode = wp.shortcode.next('gallery', '[gallery ids="' + component.state.value + '"]')
		}

		// Bail if we didn't match the shortcode or all of the content.
		if (!shortcode) return;

		// Ignore the rest of the match object.
		shortcode = shortcode.shortcode;

		attachments = wp.media.gallery.attachments(shortcode);
		selection = new wp.media.model.Selection(attachments.models, {
			props: attachments.props.toJSON(),
			multiple: true
		});

		selection.gallery = attachments.gallery;

		// Fetch the query's attachments, and then break ties from the
		// query to allow for sorting.
		selection.more().done(function () {
			// Break ties with the query.
			selection.props.set({query: false});
			selection.unmirror();
			selection.props.unset('orderby');

			component.setState( { attachments: selection.models } );
		});

		return selection;
	}
}

SocketGallery.propTypes = {
	name: PropTypes.string,
	value: PropTypes.string,
	setupLoadingFlag: PropTypes.func
}

export default SocketGallery;
