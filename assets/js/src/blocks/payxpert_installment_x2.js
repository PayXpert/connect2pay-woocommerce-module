import { decodeEntities } from '@wordpress/html-entities';
import { useEffect } from '@wordpress/element';

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const settings = getSetting('payxpert_installment_x2_data', {});
const label = decodeEntities(settings.title);

let showCb = false;

const Content = (props) => {
	const contentBlocks = [];
	showCb = (props.billing.billingAddress.country === 'FR');

	const isSeamless = Boolean(settings?.seamless);
	const prefix = settings?.seamless?.prefix;
	const { eventRegistration, emitResponse } = props;
	const onPaymentSetup = eventRegistration?.onPaymentSetup;
	const onCheckoutSuccess = eventRegistration?.onCheckoutSuccess;

	useEffect(() => {
		if (!isSeamless || !prefix || !onPaymentSetup || !emitResponse) {
			return;
		}

		const selectedMethod = document.querySelector('input[name="radio-control-wc-payment-method-options"]:checked')?.value;
		if (selectedMethod !== prefix) return;

		const removeHandler = onPaymentSetup(async () => {
			const container = document.querySelector(`.payxpert-seamless-container[data-method="${prefix}"]`);
			if (!container) return { type: emitResponse.responseTypes.ERROR };

			const payxpertNonce = container.querySelector('.payxpert_nonce')?.value;
			const customerToken = container.querySelector('.customerToken')?.value;

			if (!payxpertNonce || !customerToken) {
				return { type: emitResponse.responseTypes.ERROR };
			}

			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						payxpert_nonce: payxpertNonce,
						[`${prefix}_customer_token`]: customerToken,
					},
				},
			};
		});

		return () => {
			if (typeof removeHandler === 'function') {
				removeHandler();
			}
		};
	}, [emitResponse?.responseTypes, onPaymentSetup, prefix]);

	useEffect(() => {
		if (!isSeamless) {
			return;
		}
		if (typeof window.mountPayxpertIframe === 'function') {
			window.togglePlaceOrder();
			window.mountPayxpertIframe();
		}
	}, []);

	useEffect(() => {
		if (!isSeamless || !onCheckoutSuccess) {
			return;
		}
		const unsubscribe = onCheckoutSuccess((checkoutData) => {
			if (typeof window.payxpertStoreOrderIdFromCheckout === 'function') {
				window.payxpertStoreOrderIdFromCheckout(checkoutData);
			}
			if (typeof window.triggerPayxpertPaymentCall === 'function') {
				window.triggerPayxpertPaymentCall();
			}
		});
		return unsubscribe;
	}, [onCheckoutSuccess]);

	if (settings?.description) {
		contentBlocks.push(
			<div
				key="description"
				dangerouslySetInnerHTML={{
					__html: decodeEntities(settings.description),
				}}
			/>
		);
	}

	if (contentBlocks.length > 0) {
		return <>{contentBlocks}</>;
	}

	return '';
};

const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	const cbIcon = 'cb.png';

	if (showCb) {
		if (!settings.icons.includes(cbIcon)) {
			settings.icons.push(cbIcon);
		}
	} else {
		settings.icons = settings.icons.filter(icon => icon !== cbIcon);
	}

	return (
		<span style={{ width: '100%' }}>
			<PaymentMethodLabel text={label} />
			<Icon />
		</span>
	);
};

const Icon = () => {
	if (!Array.isArray(settings.icons) || settings.icons.length === 0 || !settings.icon_enabled) {
		return null;
	}

	return (
		<span style={{ float: 'right' }}>
			{settings.icons.map((filename, index) => (
				<img
					key={index}
					src={`${settings.icon_path}/${filename}`}
					style={{ marginLeft: '5px', verticalAlign: 'middle' }}
					alt={filename}
				/>
			))}
		</span>
	);
};

registerPaymentMethod({
	name: 'payxpert_installment_x2',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
});
