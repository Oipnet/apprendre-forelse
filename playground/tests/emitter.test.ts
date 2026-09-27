import { describe, expect, it } from 'vitest';
import { Emitter, Mailbox } from '../src/app/emitter.ts';

describe('Emitter', () => {
	it('prévient ses abonnés de ce qui est émis après leur abonnement', () => {
		const emitter = new Emitter<number>();
		const recus: number[] = [];
		emitter.emit(1);
		const off = emitter.on((n) => recus.push(n));
		emitter.emit(2);
		off();
		emitter.emit(3);
		expect(recus).toEqual([2]);
	});
});

describe('Mailbox', () => {
	it('garde ce qui arrive avant le destinataire, puis remet tout dans l\'ordre', () => {
		const boite = new Mailbox<string>();
		const recus: string[] = [];
		boite.post('a');
		boite.post('b');
		boite.deliverTo((s) => recus.push(s));
		boite.post('c');
		expect(recus).toEqual(['a', 'b', 'c']);
	});
});
