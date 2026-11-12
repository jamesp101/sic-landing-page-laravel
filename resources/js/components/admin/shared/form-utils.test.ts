import { describe, expect, it } from 'vitest';
import { parseActionError } from './form-utils';

const failure = (code: string, message: string) => ({ type: 'ActionError', code, message });
const inputError = (fields: Record<string, string[]>) => ({ type: 'ActionInputError', code: 'BAD_REQUEST', fields });
const byCode = { CONFLICT: 'email', BAD_REQUEST: 'currentPassword' };

describe('parseActionError', () => {
    it('puts an error whose code is mapped on its field', () => {
        expect(parseActionError(failure('CONFLICT', 'Email taken.'), byCode)).toEqual({
            fields: { email: 'Email taken.' },
            message: '',
        });
        expect(parseActionError(failure('BAD_REQUEST', 'Wrong password.'), byCode)).toEqual({
            fields: { currentPassword: 'Wrong password.' },
            message: '',
        });
    });

    it('keeps unmapped codes as the form-level message', () => {
        expect(parseActionError(failure('FORBIDDEN', 'Not allowed.'), byCode)).toEqual({
            fields: {},
            message: 'Not allowed.',
        });
        expect(parseActionError(failure('CONFLICT', 'Taken.'))).toEqual({ fields: {}, message: 'Taken.' });
    });

    it('uses the first message per field, ignoring the code map', () => {
        const error = inputError({ name: ['Required', 'Too short'], email: ['Invalid'] });
        expect(parseActionError(error, byCode)).toEqual({
            fields: { name: 'Required', email: 'Invalid' },
            message: '',
        });
    });

    it('shows a nested field’s message on its top-level field', () => {
        const error = inputError({ 'items.0.name': ['Required'], 'items.1.name': ['Too short'] });
        expect(parseActionError(error)).toEqual({ fields: { items: 'Required' }, message: '' });
    });
});
