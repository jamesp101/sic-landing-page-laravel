import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { postImage, uploadDownloadDirect, uploadVideo } from './upload';

vi.mock('@/lib/csrf', () => ({ csrfHeaders: async () => ({ 'X-CSRF-TOKEN': 'token' }) }));
vi.mock('@/lib/image-compress', () => ({ compressImage: async (file: File) => file }));

type Reply = { status: number; body: unknown };

/** Stand-in for XMLHttpRequest that records the PUT and answers with the next queued reply. */
class FakeXhr {
    static replies: Reply[] = [];
    static sent: { url: string; headers: Record<string, string>; file: unknown }[] = [];
    headers: Record<string, string> = {};
    url = '';
    status = 0;
    responseText = '';
    upload: { onprogress: ((event: { lengthComputable: boolean; loaded: number; total: number }) => void) | null } = {
        onprogress: null,
    };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    onabort: (() => void) | null = null;
    open(_method: string, url: string) {
        this.url = url;
    }
    setRequestHeader(name: string, value: string) {
        this.headers[name] = value;
    }
    send(file: unknown) {
        FakeXhr.sent.push({ url: this.url, headers: this.headers, file });
        const reply = FakeXhr.replies.shift() ?? { status: 200, body: '' };
        this.upload.onprogress?.({ lengthComputable: true, loaded: 1, total: 2 });
        this.status = reply.status;
        this.responseText = typeof reply.body === 'string' ? reply.body : JSON.stringify(reply.body);
        this.onload?.();
    }
}

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status });

let fetchMock: Mock;

beforeEach(() => {
    FakeXhr.replies = [];
    FakeXhr.sent = [];
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('XMLHttpRequest', FakeXhr);
});

afterEach(() => vi.unstubAllGlobals());

const bytes = (name: string, type: string) => new File(['hello'], name, { type });

describe('uploadDownloadDirect', () => {
    it('requests a grant, PUTs to its target with the file type and returns the key', async () => {
        fetchMock.mockResolvedValueOnce(json(200, { key: 'downloads/a.pdf', url: 'https://bucket.test/a' }));
        const percents: number[] = [];

        const key = await uploadDownloadDirect(bytes('a.pdf', 'application/pdf'), (percent) => percents.push(percent));

        expect(key).toBe('downloads/a.pdf');
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/admin/uploads/direct');
        expect(JSON.parse(init.body)).toEqual({ fileName: 'a.pdf', contentType: 'application/pdf', size: 5 });
        expect(FakeXhr.sent).toHaveLength(1);
        expect(FakeXhr.sent[0].url).toBe('https://bucket.test/a');
        // A bucket gets Content-Type alone, never the app's CSRF header.
        expect(FakeXhr.sent[0].headers).toEqual({ 'Content-Type': 'application/pdf' });
        expect(percents).toEqual([0, 50]);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('shows the server message when the grant is refused, and sends nothing', async () => {
        fetchMock.mockResolvedValueOnce(json(422, { message: 'This file type is not allowed.' }));

        await expect(uploadDownloadDirect(bytes('a.exe', ''))).rejects.toThrow('This file type is not allowed.');
        expect(FakeXhr.sent).toHaveLength(0);
    });

    it('falls back to a generic message when the grant has no readable body', async () => {
        fetchMock.mockResolvedValueOnce(new Response('oops', { status: 500 }));

        await expect(uploadDownloadDirect(bytes('a.pdf', 'application/pdf'))).rejects.toThrow(
            'Could not start the upload.',
        );
    });
});

describe('uploadVideo', () => {
    it('grants, PUTs with the granted type to the app route with CSRF, then completes', async () => {
        fetchMock
            .mockResolvedValueOnce(
                json(200, {
                    key: 'videos/v.mp4',
                    url: '/api/admin/uploads/video?key=videos%2Fv.mp4',
                    contentType: 'video/mp4',
                }),
            )
            .mockResolvedValueOnce(json(200, { url: '/storage/videos/v.mp4' }));
        const fractions: number[] = [];

        const result = await uploadVideo(bytes('v.mp4', 'video/mp4'), (fraction) => fractions.push(fraction));

        expect(result).toEqual({ url: '/storage/videos/v.mp4' });
        expect(fetchMock.mock.calls.map((call) => call[0])).toEqual([
            '/api/admin/uploads/video',
            '/api/admin/uploads/video/complete',
        ]);
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ key: 'videos/v.mp4' });
        expect(FakeXhr.sent[0].headers).toEqual({ 'Content-Type': 'video/mp4', 'X-CSRF-TOKEN': 'token' });
        expect(fractions).toEqual([0, 0.5, 1]);
    });

    it('rejects a wrong type or an empty file before any request', async () => {
        await expect(uploadVideo(bytes('v.mov', 'video/quicktime'))).rejects.toThrow('Choose an MP4 or WebM video.');
        await expect(uploadVideo(new File([], 'v.mp4', { type: 'video/mp4' }))).rejects.toThrow(
            'Choose a video to upload.',
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('reports the server message of a failed PUT and does not complete', async () => {
        fetchMock.mockResolvedValueOnce(
            json(200, { key: 'videos/v.mp4', url: '/api/admin/uploads/video?key=x', contentType: 'video/mp4' }),
        );
        FakeXhr.replies.push({ status: 400, body: { message: 'The upload was incomplete. Try again.' } });

        await expect(uploadVideo(bytes('v.mp4', 'video/mp4'))).rejects.toThrow('The upload was incomplete. Try again.');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('names the HTTP status when a bucket answers a failed PUT with XML', async () => {
        fetchMock.mockResolvedValueOnce(
            json(200, { key: 'videos/v.mp4', url: 'https://bucket.test/v', contentType: 'video/mp4' }),
        );
        FakeXhr.replies.push({ status: 403, body: '<Error/>' });

        await expect(uploadVideo(bytes('v.mp4', 'video/mp4'))).rejects.toThrow('The upload failed (HTTP 403).');
    });

    it('fails when completion returns no url', async () => {
        fetchMock
            .mockResolvedValueOnce(
                json(200, { key: 'videos/v.mp4', url: 'https://bucket.test/v', contentType: 'video/mp4' }),
            )
            .mockResolvedValueOnce(json(200, {}));

        await expect(uploadVideo(bytes('v.mp4', 'video/mp4'))).rejects.toThrow('Could not finish the upload.');
    });
});

describe('postImage', () => {
    it('returns the stored url with the compressed file', async () => {
        fetchMock.mockResolvedValueOnce(json(200, { url: '/storage/i.webp' }));
        const file = bytes('i.png', 'image/png');

        expect(await postImage(file, 'content')).toEqual({ url: '/storage/i.webp', file });
        expect(fetchMock.mock.calls[0][0]).toBe('/api/admin/uploads/image');
    });

    it('uses the same error parsing as the other uploads', async () => {
        fetchMock.mockResolvedValueOnce(json(422, { message: 'The image is too large.' }));
        await expect(postImage(bytes('i.png', 'image/png'), 'content')).rejects.toThrow('The image is too large.');

        fetchMock.mockRejectedValueOnce(new TypeError('offline'));
        await expect(postImage(bytes('i.png', 'image/png'), 'layer')).rejects.toThrow(
            'Upload failed. Please try again.',
        );
    });
});
