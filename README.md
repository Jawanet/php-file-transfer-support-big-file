## Advanced PHP File Downloader

This tool is an advanced, php-based download system that allows you to fetch files from the internet (based on a URL) and store them on the server. Its features are engineered for speed, reliability, and process transparency.

### 1\. ⏱️ Real-time Download Progress (Live Progress)

The core feature of this tool is the ability to display the download status live without requiring a page refresh.

*   **Instant Monitoring:** You can instantly view the amount of data already downloaded and the percentage of progress completed.
    
*   **Speed & Time:** Displays the current **transfer speed** and provides an **estimated time remaining** until the download is finished.
    
*   **Technical Insight:** This feature operates using **AJAX** (JavaScript) technology, which constantly checks the download status stored in a **PHP Session** on the server.
    

- - -

### 2\. 🔍 Automatic File Information Checking

Before initiating a large download, the system automatically attempts to gather details about the file.

*   **Size Identification:** It rigorously attempts to detect the **total file size** from the source server (remote server), allowing you to know the magnitude of the file being downloaded.
    
*   **File Naming:** Intelligently determines the **file name** from the URL, even if the original name is not explicitly provided.
    
*   **Handling Unknown Size:** If the source server does not provide file size information, the tool will notify you and proceed with the download process regardless.
    

- - -

### 3\. 🛡️ Secure File Naming

This tool ensures your files are safely stored on the server without the risk of overwriting existing files.

*   **Unique Naming Automation:** If you download a file with an existing name (e.g., `document.zip`), the system will automatically rename it to a unique identifier (e.g., `document_1.zip`).
    

- - -

### 4\. ⚙️ Download Optimization and Reliability

The download process itself is optimized for high performance and handles complex download scenarios.

*   **Large File Downloads:** Server settings are dynamically modified to permit **unlimited execution time** and a **large memory allocation** (1024MB), ideal for downloading extremely large files.
    
*   **Clean Process:** If a download error or failure occurs (HTTP error or connection interruption), the incomplete file will be **automatically deleted** to prevent junk files.
    
*   **Broad Server Support:** Utilizes the advanced PHP **cURL** library for reliable connections, capable of following redirects and securely handling complex connections (**SSL/HTTPS**).

### 5\. ⚙️ Requirement

*   *Php 8.0-8.4*
