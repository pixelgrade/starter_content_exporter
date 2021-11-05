import React from 'react'
import ReactDOM from 'react-dom'
import SocketPostSelect from './postSelect.js'
import SocketTaxSelect from './taxSelect.js'
import SocketGallery from './gallery.js'
import {
	Button,
	Checkbox,
	Container,
	Dimmer,
	Divider,
	Dropdown,
	Form,
	Grid,
	Header,
	Label,
	Loader,
	Radio,
	Segment,
} from 'semantic-ui-react'
import get from 'lodash/get'
import isArrayLike from 'lodash/isArrayLike'
import isUndefined from 'lodash/isUndefined'
import isEmpty from 'lodash/isEmpty'
import debounce from 'lodash/debounce'

wp.media.socketgallery = []

class SocketDashboard extends React.Component {

	constructor (props) {
		// this makes the this
		super(props)

		// get the current state localized by wordpress
		this.state = {
			loading: false,
			values: socket.values,
		}

		this.handleChange = this.handleChange.bind(this)
		this.inputHandleChange = this.inputHandleChange.bind(this)
		this.checkboxHandleChange = this.checkboxHandleChange.bind(this)
		this.radioHandleChange = this.radioHandleChange.bind(this)
		this.tagsHandleAddition = this.tagsHandleAddition.bind(this)
		this.multicheckboxHandleChange = this.multicheckboxHandleChange.bind(this)
		this.clean_the_house = this.clean_the_house.bind(this)
		this.setupLoadingFlag = this.setupLoadingFlag.bind(this)
	}

	render () {
		let component = this

		return <Container fluid>

			{(component.state.loading === true) ?
				<div style={{'position': 'absolute', 'top': 0, 'bottom': 0, 'right': 0, 'left': 0}}>
					<Dimmer active inverted>
						<Loader size="big"/>
						<Divider horizontal inverted>Saving changes.. Wait a second..</Divider>
					</Dimmer>
				</div>
				:
				''
			}

			{component.renderSockets()}

			<Segment color="red">
				<h3>Debug Tools</h3>
				<Button basic color="red" onClick={this.clean_the_house}>Reset</Button>
			</Segment>
		</Container>
	}

	renderSockets () {
		let component = this

		let socketKeys = Object.keys(socket.config.sockets)

		let output = []
		let groupOutput = []
		let columns = 0

		// Check if we need to group sockets into columns.
		if (get(socket, 'config.display.group', false)) {
			let socketsGroups = get(socket, 'config.display.groups', false)

			if (isArrayLike(socketsGroups)) {
				for (let i = 0; i < socketsGroups.length; i++) {
					if (!get(socketsGroups[i], 'sockets', false)) {
						continue
					}
					groupOutput = []

					if (get(socketsGroups[i], 'title', false)) {
						groupOutput.push(
							<Segment key={'socketgroup_title_' + columns}>
								<Header as="h2" content={socketsGroups[i].title}/>
								{get(socketsGroups[i], 'desc', false)
									?
									<p dangerouslySetInnerHTML={{__html: socketsGroups[i].desc}}/>
									:
									''
								}
							</Segment>
						)
					}

					socketsGroups[i].sockets.map(function (socketKey) {
						if (typeof socketKey === 'undefined') {
							return false
						}

						const sectionConfig = socket.config.sockets[socketKey]
						groupOutput.push(
							component.renderSection(socketKey, sectionConfig)
						)
						socketKeys.splice(socketKeys.indexOf(socketKey), 1)
					})

					output.push(
						<Grid.Column key={'socketgroup_' + columns}>
							{groupOutput}
						</Grid.Column>
					)

					columns++
				}
			}
		}

		// If we have any leftovers, add them in a separate column.
		if (socketKeys.length) {
			groupOutput = []

			socketKeys.map(function (socketKey) {
				if (typeof socketKey === 'undefined') {
					return false
				}

				const sectionConfig = socket.config.sockets[socketKey]
				groupOutput.push(
					component.renderSection(socketKey, sectionConfig)
				)
			})

			output.push(
				<Grid.Column key={'socketgroup_' + columns}>
					{groupOutput}
				</Grid.Column>
			)

			columns++
		}

		return <Grid columns="equal">
			<Grid.Row>
				{output}
			</Grid.Row>
		</Grid>
	}

	renderSection (sectionKey, sectionConfig) {
		let component = this

		return <Segment key={sectionKey}>
			<Header as="h2" key={sectionKey} content={sectionConfig.label} subheader={sectionConfig.desc}/>

			<Form>
				{Object.keys(sectionConfig.items).map(function (fieldKey) {
					const fieldConfig = sectionConfig.items[fieldKey]

					return component.renderField(fieldKey, fieldConfig)
				})}
			</Form>
		</Segment>
	}

	renderField (fieldKey, fieldConfig) {
		let component = this

		let output = null,
			value = '',
			description = '',
			placeholder = ''

		if (component.state.values !== null && typeof component.state.values[fieldKey] !== 'undefined') {
			value = component.state.values[fieldKey]
		}
		if (typeof fieldConfig.placeholder !== 'undefined') {
			placeholder = fieldConfig.placeholder
		}

		switch (fieldConfig.type) {
			case 'text' : {

				output = <Form.Field>
					<input placeholder={placeholder} data-name={fieldKey}
						   onInput={component.inputHandleChange} defaultValue={value}/>
				</Form.Field>
				break
			}

			case 'radio' : {
				output = <Form.Field>
					{Object.keys(fieldConfig.options).map(function (opt) {
						return <Radio key={fieldKey + opt}
									  label={fieldConfig.options[opt]}
									  name={fieldKey}
									  value={opt}
									  checked={value === opt}
									  onChange={component.radioHandleChange}
						/>
					})}
				</Form.Field>
				break
			}

			case 'checkbox' : {
				value = component.validate_options_for_checkboxes(value)

				description = ''
				if (!isUndefined(fieldConfig.desc)) {
					description = fieldConfig.desc
				}

				output = <Form.Field>
					<Checkbox
						label={description}
						placeholder={placeholder}
						data-name={fieldKey}
						onChange={component.checkboxHandleChange}
						defaultChecked={value}
					/>
				</Form.Field>
				break
			}

			case 'multicheckbox' : {
				output = <Segment>
					{Object.keys(fieldConfig.options).map(function (opt) {
						let label = fieldConfig.options[opt],
							defaultVal = false

						if (typeof value[opt] !== 'undefined' && value[opt] === 'on') {
							defaultVal = true
						}

						return <Form.Field key={fieldKey + opt}>
							<Checkbox label={label} data-name={fieldKey} data-option={opt}
									  onChange={component.multicheckboxHandleChange}
									  defaultChecked={defaultVal}/>
						</Form.Field>
					})}
				</Segment>
				break
			}

			case 'toggle' : {
				value = component.validate_options_for_checkboxes(value)

				description = ''
				if (!isUndefined(fieldConfig.desc)) {
					description = fieldConfig.desc
				}

				output = <Form.Field>
					<Checkbox
						toggle
						label={description}
						placeholder={placeholder}
						data-name={fieldKey}
						onChange={component.checkboxHandleChange}
						defaultChecked={value}
					/>
				</Form.Field>
				break
			}

			case 'select' : {
				let dropdownOptions = []

				if (!isUndefined(fieldConfig.options)) {
					Object.keys(fieldConfig.options).map(function (opt) {
						dropdownOptions.push({key: fieldKey + '__' + opt, value: opt, text: fieldConfig.options[opt]})
					})
				}

				output = <Form.Field>
					<Dropdown
						placeholder={placeholder}
						fluid
						multiple
						search
						selection
						closeOnEscape
						closeOnBlur
						value={value}
						options={dropdownOptions}
						onChange={component.radioHandleChange}/>
				</Form.Field>
				break
			}

			case 'tags' : {
				let dropdownOptions = []
				let currentValues = []

				if (!isUndefined(fieldConfig.options)) {
					Object.keys(fieldConfig.options).map(function (opt) {
						dropdownOptions.push({key: fieldKey + '__' + opt, value: opt, text: fieldConfig.options[opt]})
					})
				}

				if (!isEmpty(value)) {
					Object.keys(value).map(function (key) {
						let option = value[key]
						currentValues.push(option)

						// Check if we already have the option added.
						let foundIndex = dropdownOptions.findIndex(function (dropdownOption) {
							return dropdownOption.value === option
						})
						if (-1 !== foundIndex) {
							return
						}

						dropdownOptions.push({key: fieldKey + '__' + option, value: option, text: option})
					})
				}

				output = <Form.Field>
					<Dropdown
						className="dropdown-multiselect tags-dropdown"
						data-field_key={fieldKey}
						placeholder={placeholder}
						fluid
						multiple
						search
						selection
						allowAdditions
						options={dropdownOptions}
						value={currentValues}
						onChange={component.tagsHandleAddition}/>
				</Form.Field>
				break
			}

			case 'post_select' : {
				if (isEmpty(value)) {
					value = []
				}

				output = <SocketPostSelect key={fieldKey} name={fieldKey} value={value}
										   field={fieldConfig} placeholder={placeholder}
										   setupLoadingFlag={component.setupLoadingFlag}/>
				break
			}

			case 'tax_select' : {
				if ('' === value) {
					value = []
				}

				output = <SocketTaxSelect name={fieldKey} value={value} field={fieldConfig}
										  placeholder={placeholder}
										  setupLoadingFlag={component.setupLoadingFlag}/>
				break
			}

			case 'divider' : {
				output = <Form.Field key={fieldKey}>
					{isEmpty(fieldConfig.html) ?
						<Divider hidden/>
						:
						<Divider horizontal>{fieldConfig.html}</Divider>
					}
				</Form.Field>
				break
			}

			case 'gallery' : {
				output =
					<SocketGallery key={fieldKey} name={fieldKey} value={value} field={fieldConfig}
								   placeholder={placeholder}
								   setupLoadingFlag={component.setupLoadingFlag}/>
				break
			}

			default:
				break
		}

		if ('divider' === fieldConfig.type) {
			return output
		} else {
			description = (!isEmpty(fieldConfig.description)
					?
					<p dangerouslySetInnerHTML={{__html: fieldConfig.description}}/>
					:
					''
			)

			return <Segment key={fieldKey} padded>
				{!isEmpty(fieldConfig.label)
					?
					<Label attached="top" size="big">{fieldConfig.label}</Label>
					:
					''
				}
				{description}
				{output}
			</Segment>
		}
	}

	validate_options_for_checkboxes (value) {
		if (typeof value === 'number') {
			return (value == 1)
		}

		return (value == 'true' || value == '1')
	}

	htmlDecode (input) {
		var e = document.createElement('div')
		e.innerHTML = input
		return e.childNodes.length === 0 ? '' : e.childNodes[0].nodeValue
	}

	inputHandleChange (e) {
		e.persist()

		// every time a user types we need to delay tthis events until he stops typing
		this.delayedCallback(e)
	}

	componentWillMount () {
		this.delayedCallback = debounce(function (event) {
			// `event.target` is accessible now
			let component = this,
				name = event.target.dataset.name,
				value = event.target.value

			if (!this.state.loading) {

				this.async_loading(() => {

					jQuery.ajax({
						url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
						method: 'POST',
						beforeSend: function (xhr) {
							xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
						},
						data: {
							'socket_nonce': socket.wp_rest.socket_nonce,
							name: name,
							value: value
						}
					}).done(function (response) {
						if (response.success) {
							let new_values = component.state.values

							new_values[name] = value

							component.setState({
								loading: false,
								values: new_values
							})
						} else {
							console.log(response)
							alert('There\'s been an error when trying to save! Check the console for details.')
							component.setState({
								loading: false,
							})
						}

					}).error(function (err) {
						console.log(err)
						alert('There\'s been an error when trying to save! Check the console for details.')
						component.setState({
							loading: false,
						})
					})

				})
			}

		}, 1000)
	}

	radioHandleChange (e) {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target).parentNode,
			input = componentNode.childNodes[0],
			name = input.name,
			value = input.value

		if (!this.state.loading) {

			this.async_loading(() => {

				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: value
					}
				}).done(function (response) {
					if (response.success) {
						let new_values = component.state.values

						new_values[name] = value

						component.setState({
							loading: false,
							values: new_values
						})
					} else {
						console.log(response)
						alert('There\'s been an error when trying to save! Check the console for details.')
						component.setState({
							loading: false,
						})
					}

				}).error(function (err) {
					console.log(err)
					alert('There\'s been an error when trying to save! Check the console for details.')
					component.setState({
						loading: false,
					})
				})
			})
		}
	}

	checkboxHandleChange (e) {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target).parentNode,
			input = componentNode.childNodes[0],
			name = componentNode.dataset.name,
			value = input.value

		if (!this.state.loading) {

			this.async_loading(() => {

				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: (value === 'on') ? 1 : 0
					}
				}).done(function (response) {
					if (response.success) {
						let new_values = component.state.values

						new_values[name] = value

						component.setState({
							loading: false,
							values: new_values
						})
					} else {
						console.log(response)
						alert('There\'s been an error when trying to save! Check the console for details.')
						component.setState({
							loading: false,
						})
					}

				}).error(function (err) {
					console.log(err)
					alert('There\'s been an error when trying to save! Check the console for details.')
					component.setState({
						loading: false,
					})
				})

			})
		}

	}

	multicheckboxHandleChange (e) {
		let component = this,
			componentNode = ReactDOM.findDOMNode(e.target).parentNode,
			input = componentNode.childNodes[0],
			name = componentNode.dataset.name,
			option = componentNode.dataset.option,
			checked = !input.checked,
			value = component.state.values[name]

		if (checked) {
			value[option] = 'on'
		} else {
			delete value[option]
		}

		if (!this.state.loading) {

			this.async_loading(() => {

				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: name,
						value: value
					}
				}).done(function (response) {
					if (response.success) {
						let new_values = component.state.values

						new_values[name] = value

						component.setState({
							loading: false,
							values: new_values
						})
					} else {
						console.log(response)
						alert('There\'s been an error when trying to save! Check the console for details.')
						component.setState({
							loading: false,
						})
					}

				}).error(function (err) {
					console.log(err)
					alert('There\'s been an error when trying to save! Check the console for details.')
					component.setState({
						loading: false,
					})
				})

			})
		}

	}

	tagsHandleAddition = (e, {value}) => {
		let component = this,
			fieldName = null

		// Get at the first item, since the click may have come from inner elements like span.
		const mainFieldItem = e.target.closest('.tags-dropdown')

		// Try to get the field name.
		if (typeof mainFieldItem.dataset.field_key !== 'undefined') {
			fieldName = mainFieldItem.parentNode.dataset.field_key
		} else {
			console.log('Could not get the field name.')
			return
		}

		if (typeof component.state.values[fieldName] === 'undefined') {
			component.state.values[fieldName] = []
		}

		if (component.state.values[fieldName].indexOf(value) !== -1) {
			console.log('Value already exists')
			return
		}

		component.state.values[fieldName] = value

		if (!this.state.loading) {
			this.async_loading(() => {
				jQuery.ajax({
					url: socket.wp_rest.root + socket.wp_rest.api_base + '/option',
					method: 'POST',
					beforeSend: function (xhr) {
						xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
					},
					data: {
						'socket_nonce': socket.wp_rest.socket_nonce,
						name: fieldName,
						value: component.state.values[fieldName]
					}
				}).done(function (response) {
					if (response.success) {
						component.setState({
							loading: false,
							values: component.state.values
						})
					} else {
						console.log(response)
						alert('There\'s been an error when trying to save! Check the console for details.')
					}
				}).error(function (err) {
					console.log(err)
					alert('There\'s been an error when trying to save! Check the console for details.')
					component.setState({
						loading: false,
					})
				})
			})
		}
	}

	handleChange = (e, {value}) => {
		console.log(value)
	}

	async_loading = (cb) => {
		this.setState({loading: true}, () => {
			this.asyncTimer = setTimeout(cb, 500)
		})
	}

	update_local_state ($state) {
		this.setState($state, function () {
			jQuery.ajax({
				url: socket.wp_rest.root + socket.wp_rest.api_base + '/react_state',
				method: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
				},
				data: {
					'socket_nonce': socket.wp_rest.socket_nonce,
					state: this.state
				}
			}).done(function (response) {
				console.log(response)
			})
		})
	}

	add_notices = (state) => {
		var notices = []
		return notices
	}

	setupLoadingFlag ($val) {
		this.setState({loading: $val})
	}

	clean_the_house = () => {
		let component = this,
			test1 = Math.floor((Math.random() * 10) + 1),
			test2 = Math.floor((Math.random() * 10) + 1),
			componentNode = ReactDOM.findDOMNode(this)

		var confirm = prompt('Are you sure you want to reset Pixcare?\n\n\nOK, just do this math: ' + test1 + ' + ' + test2 + '=', '')

		if (test1 + test2 == confirm) {
			jQuery.ajax({
				url: socket.wp_rest.root + socket.wp_rest.api_base + '/cleanup',
				method: 'POST',
				beforeSend: function (xhr) {
					xhr.setRequestHeader('X-WP-Nonce', socket.wp_rest.nonce)
				},
				data: {
					'socket_nonce': socket.wp_rest.socket_nonce,
					test1: test1,
					test2: test2,
					confirm: confirm
				}
			}).done(function (response) {
				if (response.success) {
					console.log('done!')
				}
			}).error(function (e) {
				alert('Sorry I can\'t do this!')
			})
		}
	}
}

export default (SocketDashboard)
