const button = document.getElementById('download-leave-pdf');
const status = document.getElementById('pdf-download-status');

button?.addEventListener('click', async () => {
    const pages = [...document.querySelectorAll('.paper-page')];
    if (pages.length !== 2) {
        status.textContent = 'The application form pages could not be found.';
        return;
    }

    button.disabled = true;
    status.textContent = 'Preparing PDF…';

    try {
        const [{ default: html2canvas }, { jsPDF }] = await Promise.all([
            import('html2canvas'),
            import('jspdf'),
        ]);
        const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });

        for (const [index, page] of pages.entries()) {
            const canvas = await html2canvas(page, {
                backgroundColor: '#ffffff',
                scale: 2,
                logging: false,
                windowWidth: Math.max(document.documentElement.clientWidth, document.documentElement.scrollWidth),
                onclone(clonedDocument) {
                    const styles = clonedDocument.createElement('style');
                    styles.textContent = `
                        .paper-page { width: 194mm !important; height: 281mm !important; min-height: 281mm !important; max-height: 281mm !important; overflow: hidden !important; padding-bottom: 10mm !important; }
                        .paper-page + .paper-page { margin-top: 0 !important; padding-top: 5mm !important; border-top: 0 !important; }
                    `;
                    clonedDocument.head.append(styles);
                },
            });

            if (index > 0) pdf.addPage();
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.96), 'JPEG', 8, 8, 194, 281, undefined, 'FAST');
        }

        pdf.save(`application-for-leave-${button.dataset.applicationId}.pdf`);
        status.textContent = 'PDF downloaded.';
    } catch (error) {
        console.error('Unable to generate the leave application PDF.', error);
        status.textContent = 'PDF generation failed. Use Print, then choose Save as PDF.';
    } finally {
        button.disabled = false;
    }
});
