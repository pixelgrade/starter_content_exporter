import React from "react";
import ReactDOM from "react-dom";
import SocketPostSelect from "./postSelect.js";
import SocketTaxSelect from "./taxSelect.js";
import SocketGallery from "./gallery.js";

wp.media.socketgallery = [];

import {
	Button,
	Checkbox,
	Container,
	Dimmer,
	Divider,
	Dropdown,
	Form,
	Icon,
	Image,
	Grid,
	Header,
	Label,
	Loader,
	Radio,
	Segment,
	Text,
} from 'semantic-ui-react'

class SocketDashboard extends React.Component {

	constructor(props) {
		// this makes the this
		super(props);

		// get the current state localized by wordpress
		this.state = {
			loading: false,
			values: socket.values,
		};

		this.handleChange = this.handleChange.bind(this);
		this.inputHandleChange = this.inputHandleChange.bind(this);
		this.checkboxHandleChange = this.checkboxHandleChange.bind(this);
		this.radioHandleChange = this.radioHandleChange.bind(this);
		this.tagsHandleAddition = this.tagsHandleAddition.bind(this);
		this.multicheckboxHandleChange = this.multicheckboxHandleChange.bind(this);
		this.clean_the_house = this.clean_the_house.bind(this);
		this.setup_loading_flag = this.setup_loading_flag.bind(this);
	}

	render() {
		let component = this;

		return <Segment>

			{ ( component.state.loading === true ) ?
				<div style={{"position": 'absolute', "top": 0, "bottom": 0, "right": 0, "left": 0}}>
					<Dimmer active inverted>
						<Loader size='big'/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider inverted/>
						<Divider horizontal inverted>Saving ... wait a second</Divider>
					</Dimmer>
				</div>
				:
				''
			}

			<Grid>{ Object.keys(socket.config.sockets).map(function (grid_key) {
				if (typeof grid_key === "undefined") {
					return false;
				}

				var section_config = socket.config.sockets[grid_key];

				// default grid sizes, doc this
				var sizes = {...{computer: 16, tablet: 16}, ...section_config.sizes};

				var section = <Grid.Column key={grid_key} computer={sizes.computer} tablet={sizes.tablet}
				                           mobile={sizes.mobile}>
					<Segment>
						<Header as='h2' key={grid_key} content={section_config.label} subheader={section_config.desc}/>

						<Form >
							{ Object.keys(section_config.items).map(function (field_key) {
								let field = section_config.items[field_key],
									value = '';

								if ( component.state.values !== null && typeof component.state.values[field_key] !== "undefined" ) {
									value = component.state.values[field_key];
								}

								var output = null,
									placeholder = '';

								if (typeof field.placeholder !== "undefined") {
									placeholder = field.placeholder;
								}

								switch (field.type) {
									case 'text' : {

										output = <Form.Field>
											<input placeholder={placeholder} data-name={field_key}
											       onInput={component.inputHandleChange} defaultValue={value}/>
										</Form.Field>
										break;
									}

									case 'radio' : {
										output = <Form.Field>
											{ Object.keys(field.options).map(function (opt) {
												return <Radio key={ field_key + opt }
												              label={field.options[opt]}
												              name={field_key}
												              value={opt}
												              checked={value === opt}
												              onChange={component.radioHandleChange}
												/>
											})}
										</Form.Field>
										break;
									}

									case 'checkbox' : {
										value = component.validate_options_for_checkboxes(value);

										var desc = null;

										if ( ! _.isUndefined( field.desc ) ) {
											desc = field.desc;
										}

										output = <Form.Field>
											<Checkbox
												label={desc}
												placeholder={placeholder}
												data-name={field_key}
												onChange={component.checkboxHandleChange}
												defaultChecked={value}
											/>
										</Form.Field>
										break;
									}

									case 'multicheckbox' : {
										output = <Segment>
											{ Object.keys(field.options).map(function (opt) {
												let label = field.options[opt],
													defaultVal = false;

												if (typeof value[opt] !== "undefined" && value[opt] === 'on') {
													defaultVal = true;
												}

												return <Form.Field key={ field_key + opt }>
													<Checkbox label={label} data-name={field_key} data-option={opt}
													          onChange={component.multicheckboxHandleChange}
													          defaultChecked={defaultVal}/>
												</Form.Field>
											})}
										</Segment>
										break;
									}

									case 'toggle' : {
										value = component.validate_options_for_checkboxes(value);

										var desc = null;

										if ( ! _.isUndefined( field.desc ) ) {
											desc = field.desc;
										}

										output = <Form.Field>
											<Checkbox
												toggle
												label={desc}
												placeholder={placeholder}
												data-name={field_key}
												onChange={component.checkboxHandleChange}
												defaultChecked={value}
											/>
										</Form.Field>
										break;
									}

									case 'select' : {
										let dropDownOptions = [];

										{Object.keys(field.options).map(function (opt) {
											dropDownOptions.push({key: opt, value: opt, text: field.options[opt]});
										})}

										output = <Form.Field>
											<Dropdown
												placeholder={placeholder}
												search
												selection
												defaultValue={value}
												options={dropDownOptions}
												onChange={component.radioHandleChange}/>
										</Form.Field>
										break;
									}

									case 'tags' : {
										let dropDownOptions = [];
										let defaultValues = [];

										if ( value !== '' ) {
											{Object.keys(value).map(function (key) {
												let option = value[key]
												dropDownOptions.push({key: option, value: option, text: option});
												defaultValues.push(option)
											})}
										}

										output = <Form.Field>
											<Divider inverted/>
											<Dropdown
												data-field_key={field_key}
												placeholder={placeholder}
												search
												allowAdditions
												selection
												multiple
												options={dropDownOptions}
												value={defaultValues}
												onChange={component.tagsHandleAddition} />
										</Form.Field>
										break;
									}

									case 'post_select' : {
										if ( '' === value ) {
											value = []
										}

										output = <SocketPostSelect key={field_key} name={field_key} value={value} field={field} placeholder={placeholder} setup_loading_flag={component.setup_loading_flag} />
										break;
									}

									case 'tax_select' : {
										if ( '' === value ) {
											value = []
										}

										output = <SocketTaxSelect name={field_key} value={value} field={field} placeholder={placeholder} setup_loading_flag={component.setup_loading_flag} />
										break;
									}

									case 'divider' : {
										output = <Form.Field key={field_key}>
											<Divider horizontal>
												{ _.isEmpty(field.html) ? <Icon disabled name='code' /> : field.html }
											</Divider>
										</Form.Field>
										break;
									}

									case 'gallery' : {
										output = <SocketGallery key={field_key} name={field_key} value={value} field={field} placeholder={placeholder} setup_loading_flag={component.setup_loading_flag} />
										break;
									}

									default:
										break
								}

								if ( 'divider' === field.type ) {
									return output
								} else {
									var desc = ( field.description ? <Label size="small" style={{ fontSize: 12}}>{field.description}</Label> : '' )

									return <Segment  key={field_key} padded>
										{( _.isUndefined( field.label ) ) ? null : <Label attached='top' size="big">{field.label} {desc}</Label> }
										{output}
									</Segment>
								}
							})}
						</Form>
					</Segment>
				</Grid.Column>

				return section
			}) }
			</Grid>

			<Segment color="red">
				<h3>Debug Tools</h3>
				<Button basic color="red" onClick={this.clean_the_house}>Reset</Button>
			</Segment>
		</Segment>
	}

	validate_options_for_checkboxes(value) {
		if (typeof value === 'number') {
			return ( value == 1 );
		}

		return ( value == 'true' || value == '1' );
	}

	htmlDecode(input) {
		var e = document.createElement('div');
		e.innerHTML = input;
		return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
	}

	inputHandleChange(e) {
		e.persist();

		// every time a user types we need to delay tthis events until he stops typing
		this.delayedCallback(e);
	}

	componentWillMount() {
		this.delayedCallback = _.debounce(function (event) {
			// `event.target` is accessible now
			let component = this,
				name = event.target.dataset.name,
				value = event.target.value;

			if (!this.state.loading) {

				this.async_loading(() => {

					jQuery.ajax({
						url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
						method: 'POST',
						beforeSend: function (xhr) {
							xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
						},
						data: {
							'socket_nonce': socket.wp_rest.socket_nonce,
							name: name,
							value: value
						}
					}).done(function (response) {

						let new_values = component.state.values;

						new_values[name] = value;

						component.setState({
							loading: false,
							values: new_values
						});

					}).error(function (err) {
						component.setState({
							loading: true,
						});
					});

				});
			}

		}, 1000);
	}

	radioHandleChange(e) {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target).parentNode,
			input = componentNode.childNodes[0],
			name = input.name,
			value = input.value;

		if (!this.state.loading) {

			this.async_loading(() => {

				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: value
					}
				}).done(function (response) {

					let new_values = component.state.values;

					new_values[name] = value;

					component.setState({
						loading: false,
						values: new_values
					});

				}).error(function (err) {
					component.setState({
						loading: true,
					});
				});
			});
		}
	}

	checkboxHandleChange(e) {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target).parentNode,
			input = componentNode.childNodes[0],
			name = componentNode.dataset.name,
			value = input.value;

		if (!this.state.loading) {

			this.async_loading(() => {

				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: (value === 'on' ) ? 1 : 0
					}
				}).done(function (response) {

					let new_values = component.state.values;

					new_values[name] = value;

					component.setState({
						loading: false,
						values: new_values
					});

				}).error(function (err) {
					component.setState({
						loading: true,
					});
				});

			});
		}

	}

	multicheckboxHandleChange(e) {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target).parentNode,
			input = componentNode.childNodes[0],
			name = componentNode.dataset.name,
			option = componentNode.dataset.option,
			checked = !input.checked,
			value = component.state.values[name];

		if (checked) {
			value[option] = 'on';
		} else {
			delete value[option];
		}

		if (!this.state.loading) {

			this.async_loading(() => {

				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: value
					}
				}).done(function (response) {

					let new_values = component.state.values;

					new_values[name] = value;

					component.setState({
						loading: false,
						values: new_values
					});

				}).error(function (err) {
					component.setState({
						loading: true,
					});
				});

			});
		}

	}

	tagsHandleAddition = (e, { value }) => {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target),
			name = null;

		// try to get the field name
		if ( typeof e.target.parentNode.dataset.field_key !== "undefined" ) {
			name = e.target.parentNode.dataset.field_key
		// in case this is a tag removal, the field is on the ancestor
		} else if ( typeof e.target.parentNode.parentNode.dataset.field_key !== "undefined" ) {
			name = e.target.parentNode.parentNode.dataset.field_key
		} else {
			console.log('no name')
			return;
		}

		if ( typeof component.state.values[name] === "undefined" ) {
			component.state.values[name] = []
		}

		if ( component.state.values[name].indexOf( value ) !== -1 ) {
			console.log('Value already exists')
			return;
		}

		component.state.values[name] = value

		if ( ! this.state.loading ) {

			this.async_loading(() => {
				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: component.state.values[name]
					}
				}).done(function (response) {
					if ( response.success ) {
						component.setState({
							loading: false,
							values: component.state.values
						})
					} else {
						console.log(response)
					}
				}).error(function (err) {
					component.setState({
						loading: true,
					})
				})
			})
		}
	}

	handleChange = (e, { value }) => {
		console.log(value);
	}

	async_loading = (cb) => {
		this.setState({loading: true}, () => {
			this.asyncTimer = setTimeout(cb, 500);
		});
	};

	update_local_state($state) {
		this.setState($state, function () {
			jQuery.ajax({
				url: socket.wp_rest.root + socket.wp_rest.api_base +  '/react_state',
				method: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
				},
				data: {
					'socket_nonce': socket.wp_rest.socket_nonce,
					state: this.state
				}
			}).done(function (response) {
				console.log(response);
			});
		});
	}

	add_notices = (state) => {
		var notices = [];
		return notices;
	}

	setup_loading_flag( $val ){
		this.setState( { loading: $val })
	}

	clean_the_house = () => {
		let component = this,
			test1 = Math.floor((Math.random() * 10) + 1),
			test2 = Math.floor((Math.random() * 10) + 1),
			componentNode = ReactDOM.findDOMNode(this)

		var confirm = prompt("Are you sure you want to reset Pixcare?\n\n\nOK, just do this math: " + test1 + ' + ' + test2 + '=', '');

		if ( test1 + test2 == confirm ) {
			jQuery.ajax({
				url: socket.wp_rest.root + socket.wp_rest.api_base + '/cleanup',
				method: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce);
				},
				data: {
					'socket_nonce': socket.wp_rest.socket_nonce,
					test1: test1,
					test2: test2,
					confirm: confirm
				}
			}).done(function (response) {
				if (response.success) {
					console.log('done!');
				}
			}).error(function (e) {
				alert('Sorry I can\'t do this!');
			});
		}
	}
}

export default (SocketDashboard);
