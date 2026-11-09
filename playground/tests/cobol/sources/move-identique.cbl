       IDENTIFICATION DIVISION.
       PROGRAM-ID. M.
       DATA DIVISION.
       WORKING-STORAGE SECTION.
       01  A.
           05  A-NUM PIC 9(4).
       01  B-NUM PIC 9(4).
       01  C-NUM PIC 9(5).
       01  D-NUM PIC S9(4) SIGN LEADING SEPARATE.
       PROCEDURE DIVISION.
       F01.
           MOVE SPACES TO A.
           MOVE A-NUM TO B-NUM.
           DISPLAY '[' B-NUM ']'.
           MOVE '0042' TO A.
           MOVE A-NUM TO C-NUM D-NUM.
           DISPLAY '[' C-NUM '][' D-NUM ']'.
           STOP RUN.
