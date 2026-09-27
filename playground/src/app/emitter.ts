/** Un petit émetteur typé : un module prévient, ceux qui s'y sont abonnés réagissent. */
export class Emitter<T> {
	private readonly listeners = new Set<(value: T) => void>();

	/** S'abonne ; rend de quoi se désabonner. Un abonné ne reçoit que ce qui est émis après. */
	on(listener: (value: T) => void): () => void {
		this.listeners.add(listener);
		return () => this.listeners.delete(listener);
	}

	emit(value: T): void {
		for (const listener of this.listeners) listener(value);
	}
}

/**
 * Une boîte aux lettres : ce qui est posté avant que le destinataire ne soit là est gardé, puis remis
 * dans l'ordre à son arrivée ; ensuite, tout lui est remis aussitôt.
 */
export class Mailbox<T> {
	private readonly waiting: T[] = [];
	private recipient: ((value: T) => void) | undefined;

	post(value: T): void {
		if (this.recipient) this.recipient(value);
		else this.waiting.push(value);
	}

	deliverTo(recipient: (value: T) => void): void {
		this.recipient = recipient;
		for (const value of this.waiting.splice(0)) recipient(value);
	}
}
